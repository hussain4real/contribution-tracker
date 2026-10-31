<?php

declare(strict_types=1);

use FamilyFunds\D1Staging\D1RuntimeClient;
use FamilyFunds\D1Staging\D1RuntimeProfile;
use FamilyFunds\D1Staging\D1StagingSessionHandler;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Encryption\Encrypter;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Session\EncryptedStore;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

pest()->extend(TestCase::class);

final class D1SessionSaveFailure
{
    public ?string $phase = null;
}

function sessionRecoveryValue(PDO $pdo, string $sql): mixed
{
    $statement = $pdo->query($sql);
    if ($statement === false) {
        throw new RuntimeException('Could not inspect the isolated session fixture.');
    }

    return $statement->fetchColumn();
}

beforeEach(function (): void {
    require_once __DIR__.'/../../deployment/d1-proof/autoload.php';
    config(['d1-staging.enabled' => true]);
    Http::preventStrayRequests();
    $this->remote = new PDO('sqlite::memory:');
    $this->remote->exec('create table sessions(id text primary key,payload text,last_activity integer)');
    $this->id = str_repeat('a', 40);
    $this->upserts = 0;
    $this->recoveries = 0;
    $this->requests = 0;
    $this->failure = new D1SessionSaveFailure;
    $this->url = 'https://familyfunds-d1-staging-sql.familyfunds-lab.workers.dev';
    $this->database = 'familyfunds-d1-web-staging';
    $this->timeouts = [];
    $this->handler = new D1StagingSessionHandler($this->url, 'synthetic-token', $this->database, 120);
    Http::fake(function (Request $request, array $options): PromiseInterface {
        $this->requests++;
        expect($request->url())->toBe($this->url.'/query');
        $this->timeouts[] = $options['timeout'];
        $sql = $request['sql'];
        $params = $request['params'];
        assert(is_string($sql) && is_array($params));
        $saving = str_starts_with($sql, 'insert into sessions');
        if ($saving) {
            $this->upserts++;
            if ($this->failure->phase === 'http-error') {
                return Http::response(['error' => 'Unavailable'], 503);
            }
            if (in_array($this->failure->phase, ['before', 'readback-fails', 'deleted', 'newer', 'expired', 'race-delete', 'race-update', 'recovery-fails'], true)) {
                if ($this->failure->phase === 'deleted') {
                    $this->remote->exec('delete from sessions');
                }
                if ($this->failure->phase === 'newer') {
                    $this->remote->exec("update sessions set payload='bmV3ZXI='");
                }
                if ($this->failure->phase === 'expired') {
                    $this->travel(121)->minutes();
                }
                throw new ConnectionException('Synthetic session-save timeout');
            }
        }
        if (str_starts_with($sql, 'update sessions')) {
            $this->recoveries++;
            if ($this->failure->phase === 'recovery-fails') {
                throw new ConnectionException('Synthetic recovery timeout');
            }
            if ($this->failure->phase === 'race-delete') {
                $this->remote->exec('delete from sessions');
            }
            if ($this->failure->phase === 'race-update') {
                $this->remote->exec("update sessions set payload='bmV3ZXI='");
            }
        }
        if ($sql === 'select payload,last_activity from sessions where id=?' && $this->failure->phase === 'readback-fails') {
            throw new ConnectionException('Synthetic readback timeout');
        }
        $statement = $this->remote->prepare($sql);
        $statement->execute($params);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        if ($saving && $this->failure->phase === 'after') {
            throw new ConnectionException('Synthetic lost response after commit');
        }

        return Http::response(['database' => $this->database, 'result' => ['results' => $rows]]);
    });
});

it('recovers a committed encrypted session after its response is lost without another write', function (bool $production): void {
    if ($production) {
        $this->app['env'] = 'production';
        config(['app.debug' => false, 'd1-production.enabled' => true, 'd1-staging.enabled' => false]);
        $this->url = D1RuntimeProfile::PRODUCTION_URL;
        $this->database = D1RuntimeProfile::PRODUCTION_DATABASE;
        $this->handler = new D1StagingSessionHandler($this->url, 'synthetic-token', $this->database, 120);
    }
    $key = new Encrypter(random_bytes(32), 'AES-256-CBC');
    $store = new EncryptedStore('d1-staging', $this->handler, $key);
    $store->setId($this->id);
    $store->start();
    $store->put('login_user', 'synthetic-user');
    Log::shouldReceive('notice')->once()->with('D1 session save recovered', ['strategy' => 'verified_committed']);
    $this->failure->phase = 'after';
    $store->save();
    expect($this->upserts)->toBe(1)->and($this->recoveries)->toBe(0);
    $this->failure = new D1SessionSaveFailure;
    $restarted = new EncryptedStore('d1-staging', new D1StagingSessionHandler($this->url, 'synthetic-token', $this->database, 120), $key);
    $restarted->setId($this->id);
    $restarted->start();
    expect($restarted->get('login_user'))->toBe('synthetic-user');
})->with(['staging' => false, 'production' => true]);

it('recovers a save that did not commit only while the original session is unchanged', function (): void {
    $this->handler->write($this->id, 'original');
    expect($this->handler->read($this->id))->toBe('original');
    $this->failure->phase = 'before';
    Log::shouldReceive('notice')->once()->with('D1 session save recovered', ['strategy' => 'updated_unchanged']);
    expect($this->handler->write($this->id, 'updated'))->toBeTrue();
    $this->failure = new D1SessionSaveFailure;
    expect($this->handler->read($this->id))->toBe('updated');
    expect($this->upserts)->toBe(2)->and($this->recoveries)->toBe(1);
});

it('keeps a recovered session when the diagnostic logger fails', function (): void {
    $this->handler->write($this->id, 'original');
    expect($this->handler->read($this->id))->toBe('original');
    $this->failure->phase = 'before';
    Log::shouldReceive('notice')->once()->with('D1 session save recovered', ['strategy' => 'updated_unchanged'])
        ->andThrow(new RuntimeException('Synthetic logging failure'));

    expect($this->handler->write($this->id, 'updated'))->toBeTrue();
    $this->failure = new D1SessionSaveFailure;
    expect($this->handler->read($this->id))->toBe('updated');
});

it('does not recreate a new uncommitted session after a timeout', function (): void {
    $this->handler->read($this->id);
    $this->failure->phase = 'before';
    expect(fn () => $this->handler->write($this->id, 'new'))->toThrow(ConnectionException::class);
    expect(sessionRecoveryValue($this->remote, 'select count(*) from sessions'))->toBe(0);
    expect($this->upserts)->toBe(1)->and($this->recoveries)->toBe(0);
});

it('never resurrects a logout or overwrites a newer session during recovery', function (string $fault, ?string $remaining): void {
    $this->handler->write($this->id, 'original');
    $this->handler->read($this->id);
    $this->failure->phase = $fault;
    expect(fn () => $this->handler->write($this->id, 'stale'))->toThrow(ConnectionException::class);
    $payload = sessionRecoveryValue($this->remote, 'select payload from sessions');
    expect($payload)->toBe($remaining === null ? false : base64_encode($remaining));
    expect($this->upserts)->toBe(2);
})->with([
    'logout before readback' => ['deleted', null],
    'newer save before readback' => ['newer', 'newer'],
    'logout between readback and recovery' => ['race-delete', null],
    'newer save between readback and recovery' => ['race-update', 'newer'],
]);

it('fails closed when verification cannot reach D1 and does not retry a server error', function (string $fault, int $requests): void {
    $this->handler->write($this->id, 'original');
    $this->handler->read($this->id);
    $this->requests = 0;
    $this->failure->phase = $fault;
    expect(fn () => $this->handler->write($this->id, 'updated'))->toThrow($fault === 'http-error' ? RuntimeException::class : ConnectionException::class);
    expect($this->requests)->toBe($requests)->and($this->recoveries)->toBe(0);
    expect(sessionRecoveryValue($this->remote, 'select payload from sessions'))->toBe(base64_encode('original'));
})->with(['verification unavailable' => ['readback-fails', 2], 'D1 rejected save' => ['http-error', 1]]);

it('never retries a financial write through the general D1 client', function (): void {
    $attempts = 0;
    Http::swap(new Factory);
    Http::preventStrayRequests();
    Http::fake(function () use (&$attempts): never {
        $attempts++;
        throw new ConnectionException('Synthetic ambiguous payment commit');
    });
    $client = new D1RuntimeClient('https://familyfunds-d1-staging-sql.familyfunds-lab.workers.dev', 'synthetic-token', 'familyfunds-d1-web-staging');
    expect(fn () => $client->rows('insert into payments(amount) values (?)', [100]))->toThrow(ConnectionException::class);
    expect($attempts)->toBe(1);
});

it('does not refresh a session that expired while a save timed out', function (): void {
    $this->handler->write($this->id, 'original');
    $this->handler->read($this->id);
    $this->failure->phase = 'expired';
    expect(fn () => $this->handler->write($this->id, 'updated'))->toThrow(ConnectionException::class);
    expect($this->recoveries)->toBe(0);
    expect(sessionRecoveryValue($this->remote, 'select payload from sessions'))->toBe(base64_encode('original'));
});

it('bounds recovery to one guarded attempt when the replacement transport also fails', function (): void {
    $this->handler->write($this->id, 'original');
    $this->handler->read($this->id);
    $this->requests = 0;
    $this->failure->phase = 'recovery-fails';
    expect(fn () => $this->handler->write($this->id, 'updated'))->toThrow(ConnectionException::class);
    expect($this->requests)->toBe(3)->and($this->recoveries)->toBe(1);
    expect(array_unique($this->timeouts))->toBe([5]);
    expect(sessionRecoveryValue($this->remote, 'select payload from sessions'))->toBe(base64_encode('original'));
});

it('rejects a transport budget outside the bounded range', function (int $timeout): void {
    expect(fn () => new D1RuntimeClient($this->url, 'synthetic-token', $this->database, $timeout))->toThrow(RuntimeException::class);
    Http::assertNothingSent();
})->with([0, 31]);

it('returns the completed HTTP page when the final encrypted session save loses its response', function (): void {
    $handler = $this->handler;
    app('session')->extend('isolated-d1-session', fn (): D1StagingSessionHandler => $handler);
    config(['session.driver' => 'isolated-d1-session', 'session.encrypt' => true, 'session.lottery' => [0, 100]]);
    Route::middleware(StartSession::class)->get('/isolated-session-save-check', function (Illuminate\Http\Request $request): JsonResponse {
        $request->session()->put('login_user', 'synthetic-user');

        return response()->json(['page_completed' => true]);
    });
    $this->failure->phase = 'after';
    $this->getJson('/isolated-session-save-check')->assertSuccessful()->assertJson(['page_completed' => true]);
    expect($this->upserts)->toBe(1)->and($this->recoveries)->toBe(0);
});
