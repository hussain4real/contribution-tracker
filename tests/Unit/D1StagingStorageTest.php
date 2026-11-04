<?php

declare(strict_types=1);

use FamilyFunds\D1Staging\D1StagingConnection;
use FamilyFunds\D1Staging\D1StagingServiceProvider;
use FamilyFunds\D1Staging\D1StagingStorage;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response as HttpResponse;
use Illuminate\Filesystem\AwsS3V3Adapter;
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

it('guards both staging disks before dispatch and keeps an uncertain SDK attempt separate from its retry', function (string $database, string $bucket): void {
    $url = D1StagingConnection::TARGETS[$database];
    config(['d1-staging.enabled' => true, 'd1-staging.database' => $database, 'd1-staging.url' => $url, 'd1-staging.token' => 'synthetic',
        'd1-staging.r2.bucket' => $bucket, 'd1-staging.r2.key' => 'fixture',
        'd1-staging.r2.secret' => 'secret', 'd1-staging.r2.admission' => '1']);
    (new D1StagingServiceProvider($this->app))->register();

    $first = ['id' => (string) Str::uuid(), 'receipt' => (string) Str::uuid()];
    $retry = ['id' => (string) Str::uuid(), 'receipt' => (string) Str::uuid()];
    Http::fake([
        $url.'/storage-write/begin' => Http::sequence()->push($first)->push($retry)->push([], 503),
        $url.'/storage-write/complete' => function (Request $request) use ($retry) {
            expect($request->hasHeader('Authorization', 'Bearer synthetic'))->toBeTrue()
                ->and($request->hasHeader('X-Storage-Operation', $retry['id']))->toBeTrue()
                ->and($request->hasHeader('X-Storage-Receipt', $retry['receipt']))->toBeTrue();

            return Http::response('', 204);
        },
    ]);

    $attempts = 0;
    foreach (['local', 'public'] as $name) {
        $settings = config()->array("filesystems.disks.$name");
        $settings['retries'] = 1;
        $settings['http_handler'] = function () use (&$attempts) {
            $attempts++;

            return Create::promiseFor(new HttpResponse($attempts === 1 ? 500 : 200));
        };
        $disk = app(FilesystemManager::class)->build($settings);
        if ($name === 'public') {
            expect(fn () => $disk->put('synthetic.txt', 'fixture'))->toThrow(UnableToWriteFile::class, 'Staging R2 write admission unavailable.');
        } else {
            expect($disk->put('synthetic.txt', 'fixture'))->toBeTrue();
        }
        expect($attempts)->toBe(2);
    }
    Http::assertSentCount(4);
})->with([
    ['familyfunds-d1-web-staging', 'familyfunds-d1-staging-private'],
    ['familyfunds-d1-staging-clone-20261104t184003z', 'familyfunds-d1-staging-clone-20261104t184003z-files'],
]);

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
    $settings = config()->array('filesystems.disks.public');
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
        if (! $disk instanceof AwsS3V3Adapter) {
            throw new LogicException('Expected an S3 disk.');
        }
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

it('refuses a replacement runtime paired with legacy staging resources', function (string $setting, string $value, string $error): void {
    $database = D1StagingConnection::REPLACEMENT_DATABASE;
    config(['d1-staging.enabled' => true, 'd1-staging.database' => $database, 'd1-staging.url' => D1StagingConnection::TARGETS[$database],
        'd1-staging.token' => 'synthetic', 'd1-staging.r2.bucket' => $database.'-files', 'd1-staging.r2.key' => 'fixture',
        'd1-staging.r2.secret' => 'secret', 'd1-staging.r2.admission' => '1']);
    config([$setting => $value]);
    expect(fn () => (new D1StagingServiceProvider($this->app))->register())->toThrow($error);
})->with([
    ['d1-staging.database', 'familyfunds-d1-web-staging', 'its own database'],
    ['d1-staging.url', 'https://familyfunds-d1-staging-sql.familyfunds-lab.workers.dev', 'explicitly isolated database and endpoint'],
    ['d1-staging.r2.bucket', 'familyfunds-d1-staging-private', 'isolated staging bucket'],
    ['d1-staging.r2.admission', '0', 'Replacement staging requires R2 admission'],
]);
