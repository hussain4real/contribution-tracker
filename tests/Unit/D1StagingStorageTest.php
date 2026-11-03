<?php

declare(strict_types=1);

use FamilyFunds\D1Staging\D1StagingConnection;
use FamilyFunds\D1Staging\D1StagingServiceProvider;
use FamilyFunds\D1Staging\D1StagingStorage;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response as HttpResponse;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use League\Flysystem\UnableToWriteFile;
use Tests\TestCase;

pest()->extend(TestCase::class);

beforeEach(function (): void {
    require_once __DIR__.'/../../deployment/d1-proof/autoload.php';
});

it('uses the standard S3 driver with private visibility for staging assets', function (): void {
    $configuration = D1StagingStorage::configuration('familyfunds-d1-staging-private', 'synthetic-access-key', 'synthetic-secret');
    $disk = app(FilesystemManager::class)->build($configuration);
    expect($disk)->toBeInstanceOf(FilesystemAdapter::class)
        ->and($disk->getConfig()['visibility'])->toBe('private')
        ->and($disk->getConfig()['root'])->toBe('private')
        ->and($disk->getConfig()['bucket'])->toBe('familyfunds-d1-staging-private');
});

it('refuses another bucket before creating a storage client', function (): void {
    expect(fn () => D1StagingStorage::configuration('production', 'synthetic-key', 'synthetic-secret'))
        ->toThrow(LogicException::class, 'isolated staging bucket');
});

it('guards both staging disks before dispatch and keeps an uncertain SDK attempt separate from its retry', function (): void {
    $url = D1StagingConnection::TARGETS['familyfunds-d1-web-staging'];
    config(['d1-staging.enabled' => true, 'd1-staging.url' => $url, 'd1-staging.token' => 'synthetic',
        'd1-staging.r2.bucket' => 'familyfunds-d1-staging-private', 'd1-staging.r2.key' => 'fixture',
        'd1-staging.r2.secret' => 'secret', 'd1-staging.r2.admission' => '1']);
    (new D1StagingServiceProvider($this->app))->register();

    $pending = [];
    $paused = false;
    Http::fake(function (Request $request) use (&$pending, &$paused, $url) {
        expect($request->hasHeader('Authorization', 'Bearer synthetic'))->toBeTrue();
        if ($request->url() === $url.'/storage-write/begin') {
            if ($paused) {
                return Http::response([], 503);
            }
            $id = (string) Str::uuid();
            $pending[$id] = (string) Str::uuid();

            return Http::response(['id' => $id, 'receipt' => $pending[$id]]);
        }
        expect($request->url())->toBe($url.'/storage-write/complete');
        $id = $request->header('X-Storage-Operation')[0];
        expect($request->header('X-Storage-Receipt')[0])->toBe($pending[$id]);
        unset($pending[$id]);

        return Http::response('', 204);
    });

    $attempts = 0;
    foreach (['local', 'public'] as $name) {
        $settings = config("filesystems.disks.$name");
        $settings['retries'] = 1;
        $settings['http_handler'] = function () use (&$attempts) {
            $attempts++;

            return Create::promiseFor(new HttpResponse($attempts === 1 ? 500 : 200));
        };
        $disk = app(FilesystemManager::class)->build($settings);
        if ($name === 'public') {
            $paused = true;
            expect(fn () => $disk->put('synthetic.txt', 'fixture'))->toThrow(UnableToWriteFile::class, 'Staging R2 write admission unavailable.');
        } else {
            expect($disk->put('synthetic.txt', 'fixture'))->toBeTrue();
        }
        expect($attempts)->toBe(2)->and($pending)->toHaveCount(1);
    }
    Http::assertSentCount(4);
});

it('refuses storage admission when the application could already hold a disk', function (): void {
    app('filesystem')->disk('local');
    config(['d1-staging.enabled' => true, 'd1-staging.url' => D1StagingConnection::TARGETS['familyfunds-d1-web-staging'],
        'd1-staging.token' => 'synthetic', 'd1-staging.r2.admission' => '1']);
    expect(fn () => (new D1StagingServiceProvider($this->app))->register())->toThrow(LogicException::class, 'before filesystem resolution');
});

it('returns a confirmed R2 write once when the coordinator acknowledgement is unavailable', function (): void {
    $url = D1StagingConnection::TARGETS['familyfunds-d1-web-staging'];
    config(['d1-staging.enabled' => true, 'd1-staging.url' => $url, 'd1-staging.token' => 'synthetic',
        'd1-staging.r2.bucket' => 'familyfunds-d1-staging-private', 'd1-staging.r2.key' => 'fixture',
        'd1-staging.r2.secret' => 'secret', 'd1-staging.r2.admission' => '1']);
    (new D1StagingServiceProvider($this->app))->register();
    Http::fake([
        $url.'/storage-write/begin' => Http::response(['id' => (string) Str::uuid(), 'receipt' => (string) Str::uuid()]),
        $url.'/storage-write/complete' => Http::response([], 503),
    ]);
    $attempts = 0;
    $settings = config('filesystems.disks.public');
    $settings['http_handler'] = function () use (&$attempts) {
        $attempts++;

        return Create::promiseFor(new HttpResponse(200));
    };
    expect(app(FilesystemManager::class)->build($settings)->put('synthetic.txt', 'fixture'))->toBeTrue()
        ->and($attempts)->toBe(1);
    Http::assertSentCount(2);
});

it('refuses delegated staging writes before issuing a URL or registering an attempt', function (): void {
    $url = D1StagingConnection::TARGETS['familyfunds-d1-web-staging'];
    config(['d1-staging.enabled' => true, 'd1-staging.url' => $url, 'd1-staging.token' => 'synthetic',
        'd1-staging.r2.bucket' => 'familyfunds-d1-staging-private', 'd1-staging.r2.key' => 'fixture',
        'd1-staging.r2.secret' => 'secret', 'd1-staging.r2.admission' => '1']);
    (new D1StagingServiceProvider($this->app))->register();
    Http::fake([$url.'/storage-write/begin' => Http::response(['id' => (string) Str::uuid(), 'receipt' => (string) Str::uuid()])]);
    foreach (['local', 'public'] as $name) {
        $disk = app(FilesystemManager::class)->disk($name);
        expect($disk->providesTemporaryUploadUrls())->toBeFalse();
        expect(fn () => $disk->temporaryUploadUrl('example.txt', now()->addMinute()))
            ->toThrow(LogicException::class, 'Delegated staging writes');
        $s3 = $disk->getClient();
        $delete = $s3->getCommand('DeleteObject', ['Bucket' => $disk->getConfig()['bucket'], 'Key' => 'example.txt']);
        expect(fn () => $s3->createPresignedRequest($delete, '+1 minute'))
            ->toThrow(LogicException::class, 'Delegated staging writes');
        expect(parse_url($disk->temporaryUrl('example.txt', now()->addMinute()), PHP_URL_PATH))
            ->toBe('/familyfunds-d1-staging-private/'.($name === 'local' ? 'private' : 'public').'/example.txt');
    }
    Http::assertNothingSent();
});
