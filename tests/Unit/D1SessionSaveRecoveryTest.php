<?php

declare(strict_types=1);

use FamilyFunds\D1Staging\D1RuntimeClient;
use FamilyFunds\D1Staging\D1RuntimeProfile;
use FamilyFunds\D1Staging\D1StagingSessionHandler;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Contracts\Encryption\Encrypter as EncrypterContract;
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
use Laravel\Nightwatch\Core;
use Laravel\Nightwatch\Ingest;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

pest()->extend(TestCase::class);

final class D1SessionSaveFailure
{
    public ?string $phase = null;

    /** @var array{sql: string, params: array<mixed>}|null */
    public ?array $delayed = null;
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
        $saving = str_starts_with($sql, 'update sessions set payload=?,last_activity=? where id=? and last_activity=?');
        if ($saving) {
            $this->upserts++;
            if ($this->failure->phase === 'delayed') {
                $this->failure->delayed = ['sql' => $sql, 'params' => $params];
                throw new ConnectionException('Synthetic delayed session-save timeout');
            }
            if ($this->failure->phase === 'http-error') {
                return Http::response(['error' => 'Unavailable'], 503);
            }
            if (in_array($this->failure->phase, ['before', 'readback-fails', 'deleted', 'newer', 'expired', 'race-delete', 'race-update', 'race-expire', 'recovery-fails'], true)) {
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
        if (str_starts_with($sql, 'update sessions') && ! $saving) {
            $this->recoveries++;
            if (str_starts_with($sql, 'update sessions set last_activity=max')) {
                if ($this->failure->phase === 'equivalent-logout') {
                    $this->remote->exec('delete from sessions');
                }
                if ($this->failure->phase === 'equivalent-changed') {
                    $this->remote->exec("update sessions set payload='bmV3ZXI='");
                }
                if ($this->failure->phase === 'equivalent-expired') {
                    $this->remote->exec('update sessions set last_activity=unixepoch()-7260');
                }
            }
            if ($this->failure->phase === 'recovery-fails') {
                throw new ConnectionException('Synthetic recovery timeout');
            }
            if ($this->failure->phase === 'race-delete') {
                $this->remote->exec('delete from sessions');
            }
            if ($this->failure->phase === 'race-update') {
                $this->remote->exec("update sessions set payload='bmV3ZXI='");
            }
            if ($this->failure->phase === 'race-expire') {
                $this->remote->sqliteCreateFunction('unixepoch', fn (): int => time() + 7260);
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

it('preserves authentication session rotation and rejects a stale request after logout', function (): void {
    $key = new Encrypter(random_bytes(32), 'AES-256-CBC');
    $store = new EncryptedStore('d1-staging', $this->handler, $key);
    $store->setId($this->id);
    $store->start();
    $store->put('login_user', 'synthetic-user');
    $store->migrate(true);
    $authenticatedId = $store->getId();
    $store->save();

    $stale = new EncryptedStore('d1-staging', new D1StagingSessionHandler($this->url, 'synthetic-token', $this->database, 120), $key);
    $stale->setId($authenticatedId);
    $stale->start();
    expect($stale->get('login_user'))->toBe('synthetic-user');

    $store->invalidate();
    $store->save();
    $loggedOutId = $store->getId();
    expect($loggedOutId)->not->toBe($authenticatedId)
        ->and(fn () => $stale->save())->toThrow(RuntimeException::class, 'session changed');

    $next = new EncryptedStore('d1-staging', new D1StagingSessionHandler($this->url, 'synthetic-token', $this->database, 120), $key);
    $next->setId($loggedOutId);
    $next->start();
    expect($next->get('login_user'))->toBeNull();
    $old = new EncryptedStore('d1-staging', new D1StagingSessionHandler($this->url, 'synthetic-token', $this->database, 120), $key);
    $old->setId($authenticatedId);
    $old->start();
    expect($old->get('login_user'))->toBeNull();
});

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

it('does not recover a timed-out session save after the session expires during recovery', function (): void {
    $this->handler->write($this->id, 'original');
    expect($this->handler->read($this->id))->toBe('original');
    $this->failure->phase = 'race-expire';

    expect(fn () => $this->handler->write($this->id, 'updated'))->toThrow(ConnectionException::class)
        ->and(sessionRecoveryValue($this->remote, 'select payload from sessions'))->toBe(base64_encode('original'));
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

it('does not let a delayed timed-out save undo a later logout or session update', function (bool $logout): void {
    $this->handler->write($this->id, 'original');
    expect($this->handler->read($this->id))->toBe('original');
    $this->failure->phase = 'delayed';
    Log::shouldReceive('notice')->once();
    expect($this->handler->write($this->id, 'recovered'))->toBeTrue();

    if ($logout) {
        $this->remote->exec('delete from sessions');
    } else {
        $this->remote->exec("update sessions set payload='bmV3ZXI='");
    }
    $delayed = $this->failure->delayed;
    if ($delayed === null) {
        throw new RuntimeException('Expected a delayed session save.');
    }
    $statement = $this->remote->prepare($delayed['sql']);
    $statement->execute($delayed['params']);
    expect(sessionRecoveryValue($this->remote, 'select payload from sessions'))
        ->toBe($logout ? false : 'bmV3ZXI=');
})->with(['logout' => true, 'newer save' => false]);

it('refuses an existing session save if the session changed or was deleted after the read', function (bool $logout): void {
    $this->handler->write($this->id, 'original');
    expect($this->handler->read($this->id))->toBe('original');
    if ($logout) {
        $this->remote->exec('delete from sessions');
    } else {
        $this->remote->exec("update sessions set payload='bmV3ZXI='");
    }

    expect(fn () => $this->handler->write($this->id, 'stale'))->toThrow(RuntimeException::class, 'session changed')
        ->and(sessionRecoveryValue($this->remote, 'select payload from sessions'))
        ->toBe($logout ? false : 'bmV3ZXI=');
})->with(['logout' => true, 'newer save' => false]);

it('does not silently overwrite a concurrent session save', function (): void {
    $this->handler->write($this->id, 'original');
    $other = new D1StagingSessionHandler($this->url, 'synthetic-token', $this->database, 120);
    expect($this->handler->read($this->id))->toBe('original')
        ->and($other->read($this->id))->toBe('original')
        ->and($other->write($this->id, 'newer'))->toBeTrue();

    expect(fn () => $this->handler->write($this->id, 'stale'))->toThrow(RuntimeException::class, 'session changed')
        ->and($other->read($this->id))->toBe('newer');
});

it('allows concurrent encrypted session saves when neither changes session contents', function (): void {
    $key = new Encrypter(random_bytes(32), 'AES-256-CBC');
    $this->app->instance(EncrypterContract::class, $key);
    config(['session.encrypt' => true]);
    $makeStore = fn (): EncryptedStore => new EncryptedStore('d1-staging', new D1StagingSessionHandler($this->url, 'synthetic-token', $this->database, 120), $key, $this->id);
    $initial = $makeStore();
    $initial->start();
    $initial->put('user', 'synthetic-user');
    $initial->save();

    $first = $makeStore();
    $second = $makeStore();
    $first->start();
    $second->start();
    $first->save();
    $second->save();

    $final = $makeStore();
    $final->start();
    expect($final->get('user'))->toBe('synthetic-user');
});

it('keeps the current session version after refreshing equivalent contents', function (): void {
    $this->handler->write($this->id, 'unchanged');
    expect($this->handler->read($this->id))->toBe('unchanged');
    $this->remote->exec('update sessions set last_activity=last_activity+1');

    expect($this->handler->write($this->id, 'unchanged'))->toBeTrue()
        ->and($this->handler->write($this->id, 'updated'))->toBeTrue()
        ->and($this->handler->read($this->id))->toBe('updated');
});

it('does not overwrite changed encrypted session contents during a concurrent save', function (): void {
    $key = new Encrypter(random_bytes(32), 'AES-256-CBC');
    $this->app->instance(EncrypterContract::class, $key);
    config(['session.encrypt' => true]);
    $makeStore = fn (): EncryptedStore => new EncryptedStore('d1-staging', new D1StagingSessionHandler($this->url, 'synthetic-token', $this->database, 120), $key, $this->id);
    $initial = $makeStore();
    $initial->start();
    $initial->put('user', 'original');
    $initial->save();
    $first = $makeStore();
    $second = $makeStore();
    $first->start();
    $second->start();
    $first->put('user', 'newer');
    $first->save();
    $second->put('user', 'stale');

    expect(fn () => $second->save())->toThrow(RuntimeException::class, 'session changed');
    $final = $makeStore();
    $final->start();
    expect($final->get('user'))->toBe('newer');
});

it('fails closed when the concurrent encrypted session cannot be decoded', function (string $stored): void {
    $key = new Encrypter(random_bytes(32), 'AES-256-CBC');
    $this->app->instance(EncrypterContract::class, $key);
    config(['session.encrypt' => true]);
    $this->handler->write($this->id, $key->encrypt('original'));
    $this->handler->read($this->id);
    $statement = $this->remote->prepare('update sessions set payload=?');
    $statement->execute([$stored]);

    expect(fn () => $this->handler->write($this->id, $key->encrypt('original')))->toThrow(RuntimeException::class, 'session changed')
        ->and(sessionRecoveryValue($this->remote, 'select payload from sessions'))->toBe($stored);
})->with(['invalid base64' => '%%%', 'invalid encryption' => base64_encode('not-an-encrypted-session')]);

it('does not refresh an equivalent session after a concurrent logout, change or expiry', function (string $fault): void {
    $key = new Encrypter(random_bytes(32), 'AES-256-CBC');
    $this->app->instance(EncrypterContract::class, $key);
    config(['session.encrypt' => true]);
    $makeStore = fn (): EncryptedStore => new EncryptedStore('d1-staging', new D1StagingSessionHandler($this->url, 'synthetic-token', $this->database, 120), $key, $this->id);
    $initial = $makeStore();
    $initial->start();
    $initial->put('user', 'original');
    $initial->save();
    $first = $makeStore();
    $second = $makeStore();
    $first->start();
    $second->start();
    $first->save();
    $this->failure->phase = $fault;
    if ($fault === 'equivalent-already-expired') {
        $this->remote->exec('update sessions set last_activity=unixepoch()-7260');
    }

    expect(fn () => $second->save())->toThrow(RuntimeException::class, 'session changed');
    if ($fault === 'equivalent-logout') {
        expect(sessionRecoveryValue($this->remote, 'select count(*) from sessions'))->toBe(0);
    } elseif ($fault === 'equivalent-changed') {
        expect(sessionRecoveryValue($this->remote, 'select payload from sessions'))->toBe('bmV3ZXI=');
    } else {
        expect(sessionRecoveryValue($this->remote, 'select last_activity<=unixepoch()-7200 from sessions'))->toBe(1);
    }
})->with([
    'logout' => 'equivalent-logout',
    'changed' => 'equivalent-changed',
    'expires during refresh' => 'equivalent-expired',
    'already expired' => 'equivalent-already-expired',
]);

it('reads an expired session as empty and replaces only the unchanged expired record', function (): void {
    $this->handler->write($this->id, 'expired');
    $this->remote->exec('update sessions set last_activity=unixepoch()-7260');
    expect($this->handler->read($this->id))->toBe('');

    expect($this->handler->write($this->id, 'fresh'))->toBeTrue()
        ->and($this->handler->read($this->id))->toBe('fresh');
});

it('does not restore a new session when its timed-out save finishes after logout and a new reservation', function (): void {
    expect($this->handler->read($this->id))->toBe('');
    $this->failure->phase = 'delayed';
    expect(fn () => $this->handler->write($this->id, 'stale authenticated state'))->toThrow(ConnectionException::class);
    $delayed = $this->failure->delayed;
    if ($delayed === null) {
        throw new RuntimeException('Expected a delayed session save.');
    }
    $this->failure = new D1SessionSaveFailure;
    $other = new D1StagingSessionHandler($this->url, 'synthetic-token', $this->database, 120);
    expect($other->read($this->id))->toBe('')
        ->and($other->write($this->id, 'newer authenticated state'))->toBeTrue()
        ->and($other->destroy($this->id))->toBeTrue();
    $fresh = new D1StagingSessionHandler($this->url, 'synthetic-token', $this->database, 120);
    expect($fresh->read($this->id))->toBe('');

    $statement = $this->remote->prepare($delayed['sql']);
    $statement->execute($delayed['params']);
    expect($fresh->read($this->id))->toBe('');
});

it('keeps a recent session reservation during garbage collection and removes an old one', function (): void {
    expect($this->handler->read($this->id))->toBe('')
        ->and($this->handler->gc(7200))->toBe(0);
    $this->remote->exec('update sessions set last_activity=-(unixepoch()-301)');

    expect($this->handler->gc(7200))->toBe(1)
        ->and(sessionRecoveryValue($this->remote, 'select count(*) from sessions'))->toBe(0);
});

it('reports a recovered production session to Nightwatch without relying on the default logger', function (bool $monitorFails): void {
    $this->app['env'] = 'production';
    config(['app.debug' => false, 'd1-production.enabled' => true, 'd1-staging.enabled' => false, 'nightwatch.enabled' => true]);
    $this->url = D1RuntimeProfile::PRODUCTION_URL;
    $this->database = D1RuntimeProfile::PRODUCTION_DATABASE;
    $this->handler = new D1StagingSessionHandler($this->url, 'synthetic-token', $this->database, 120);
    $this->handler->write($this->id, 'original');
    expect($this->handler->read($this->id))->toBe('original');
    $this->failure->phase = 'before';
    Log::shouldReceive('notice')->once()->andThrow(new RuntimeException('Synthetic default logger failure'));
    if ($monitorFails) {
        Log::shouldReceive('channel')->once()->with('nightwatch')->andThrow(new RuntimeException('Synthetic monitoring failure'));
    } else {
        $monitor = Mockery::mock(LoggerInterface::class);
        $monitor->shouldReceive('notice')->once()->with('D1 session save recovered', ['strategy' => 'updated_unchanged']);
        Log::shouldReceive('channel')->once()->with('nightwatch')->andReturn($monitor);
    }

    expect($this->handler->write($this->id, 'updated'))->toBeTrue();
    $this->failure = new D1SessionSaveFailure;
    expect($this->handler->read($this->id))->toBe('updated');
})->with(['monitor available' => false, 'monitor unavailable' => true]);

it('sends a recovered production session notice through the real Nightwatch channel and ingest', function (): void {
    $this->app['env'] = 'production';
    config(['app.debug' => false, 'd1-production.enabled' => true, 'd1-staging.enabled' => false, 'nightwatch.enabled' => true]);
    $this->url = D1RuntimeProfile::PRODUCTION_URL;
    $this->database = D1RuntimeProfile::PRODUCTION_DATABASE;
    $this->handler = new D1StagingSessionHandler($this->url, 'synthetic-token', $this->database, 120);
    $this->handler->write($this->id, 'original');
    expect($this->handler->read($this->id))->toBe('original');
    $this->failure->phase = 'before';

    $core = app(Core::class);
    $ingest = $core->ingest;
    if (! $ingest instanceof Ingest) {
        throw new RuntimeException('Expected the installed Nightwatch ingest.');
    }
    $ingest->flush();
    $core->config['enabled'] = true;
    $core->sample(1.0);
    $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    if ($sockets === false) {
        throw new RuntimeException('Could not create the isolated Nightwatch connection.');
    }
    fwrite($sockets[1], '2:OK');
    $ingest->streamFactory = static fn (): mixed => $sockets[0];

    expect($this->handler->write($this->id, 'updated'))->toBeTrue();
    $core->digest();
    $core->config['enabled'] = false;
    $wire = stream_get_contents($sockets[1]);
    fclose($sockets[1]);
    if (! is_string($wire)) {
        throw new RuntimeException('Could not read the isolated Nightwatch connection.');
    }
    $frame = explode(':', $wire, 4);
    if (count($frame) !== 4) {
        throw new RuntimeException('Expected a Nightwatch payload frame.');
    }
    $records = json_decode($frame[3], true, flags: JSON_THROW_ON_ERROR);
    if (! is_array($records)) {
        throw new RuntimeException('Expected Nightwatch records.');
    }
    expect($records)->toHaveCount(1)
        ->and($records[0])->toMatchArray([
            't' => 'log', 'message' => 'D1 session save recovered', 'level' => 'notice',
            'context' => '{"strategy":"updated_unchanged"}',
        ]);
    $this->failure = new D1SessionSaveFailure;
    expect($this->handler->read($this->id))->toBe('updated');
});

it('does not recreate a new uncommitted session after a timeout', function (): void {
    $this->handler->read($this->id);
    $this->failure->phase = 'before';
    expect(fn () => $this->handler->write($this->id, 'new'))->toThrow(ConnectionException::class);
    expect(sessionRecoveryValue($this->remote, 'select count(*) from sessions where last_activity>0'))->toBe(0);
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
