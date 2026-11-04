<?php

declare(strict_types=1);

namespace FamilyFunds\D1Staging;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use SessionHandlerInterface;
use Throwable;

final class D1StagingSessionHandler implements SessionHandlerInterface
{
    private readonly D1RuntimeClient $client;

    /** @var array<string, array{payload: string, last_activity: int}> */
    private array $readSessions = [];

    public function __construct(private readonly string $url, private readonly string $token, private readonly string $database, private readonly int $lifetime)
    {
        D1RuntimeProfile::assertTarget($database, $url, $token);
        if ($lifetime < 1) {
            throw new RuntimeException('D1 session lifetime must be positive.');
        }
        $this->client = new D1RuntimeClient($url, $token, $database, timeout: 5);
    }

    public function open(string $path, string $name): bool
    {
        return true;
    }

    public function close(): bool
    {
        return true;
    }

    public function read(string $id): string
    {
        unset($this->readSessions[$id]);
        $rows = $this->query('select payload,last_activity,last_activity>unixepoch()-? as active from sessions where id=?', [$this->lifetime * 60, $id]);
        if ($rows === []) {
            $marker = base64_encode(random_bytes(32));
            $reserved = $this->query('insert into sessions(id,payload,last_activity) values (?,?,-unixepoch()) on conflict(id) do nothing returning payload,last_activity', [$id, $marker]);
            if ($reserved !== []) {
                $created = $reserved[0];
                if (($created['payload'] ?? null) !== $marker || ! is_int($created['last_activity'] ?? null) || $created['last_activity'] >= 0) {
                    throw new RuntimeException('Invalid session reservation.');
                }
                $this->readSessions[$id] = ['payload' => $marker, 'last_activity' => $created['last_activity']];

                return '';
            }
            $rows = $this->query('select payload,last_activity,last_activity>unixepoch()-? as active from sessions where id=?', [$this->lifetime * 60, $id]);
            if ($rows === []) {
                throw new RuntimeException('The session changed before it could be read.');
            }
        }
        $payload = $rows[0]['payload'];
        $lastActivity = $rows[0]['last_activity'];
        $active = $rows[0]['active'] ?? null;
        if (! is_string($payload) || ! is_int($lastActivity) || ! in_array($active, [0, 1], true)) {
            throw new RuntimeException('Invalid staging session payload.');
        }
        $this->readSessions[$id] = ['payload' => $payload, 'last_activity' => $lastActivity];
        if ($active === 0) {
            return '';
        }
        $decoded = base64_decode($payload, true);
        if ($decoded === false) {
            throw new RuntimeException('Corrupt staging session payload.');
        }

        return $decoded;
    }

    public function write(string $id, string $data): bool
    {
        $payload = base64_encode($data);
        $lastActivity = now()->getTimestamp();
        $previous = $this->readSessions[$id] ?? null;
        if ($previous === null) {
            $this->read($id);
            $previous = $this->readSessions[$id] ?? null;
        }
        if ($previous === null) {
            throw new RuntimeException('The session could not be reserved.');
        }
        $committed = ['payload' => $payload, 'last_activity' => $lastActivity];
        try {
            $saved = $this->query('update sessions set payload=?,last_activity=? where id=? and last_activity=? and payload=? returning id', [$payload, $lastActivity, $id, $previous['last_activity'], $previous['payload']]);
            if ($saved !== [['id' => $id]]) {
                $committed = $this->refreshEquivalentSession($id, $data, $lastActivity);
                if ($committed === null) {
                    throw new RuntimeException('The session changed before it could be saved.');
                }
            }
        } catch (ConnectionException $exception) {
            $client = new D1RuntimeClient($this->url, $this->token, $this->database, timeout: 5);
            $rows = $client->rows('select payload,last_activity from sessions where id=?', [$id]);
            $current = $rows[0] ?? null;
            $recovery = 'verified_committed';
            if ($current !== ['payload' => $payload, 'last_activity' => $lastActivity]) {
                if ($current !== $previous || $previous['last_activity'] <= now()->getTimestamp() - $this->lifetime * 60) {
                    throw $exception;
                }
                $saved = $client->rows('update sessions set payload=?,last_activity=? where id=? and payload=? and last_activity=? and last_activity>unixepoch()-? returning id', [$payload, $lastActivity, $id, $previous['payload'], $previous['last_activity'], $this->lifetime * 60]);
                if ($saved !== [['id' => $id]]) {
                    throw $exception;
                }
                $recovery = 'updated_unchanged';
            }
            try {
                Log::notice('D1 session save recovered', ['strategy' => $recovery]);
            } catch (Throwable) {
                // Session recovery must not fail because logging is unavailable.
            }
            if (config('d1-production.enabled') === true && config('nightwatch.enabled') === true) {
                try {
                    Log::channel('nightwatch')->notice('D1 session save recovered', ['strategy' => $recovery]);
                } catch (Throwable) {
                    // Monitoring must not fail a recovered session.
                }
            }
        }
        $this->readSessions[$id] = $committed;

        return true;
    }

    public function destroy(string $id): bool
    {
        unset($this->readSessions[$id]);
        $this->query('delete from sessions where id=?', [$id]);

        return true;
    }

    public function gc(int $max_lifetime): int
    {
        $rows = $this->query('delete from sessions where id in (select id from sessions where (last_activity>0 and last_activity<=unixepoch()-?) or (last_activity<0 and last_activity>=-(unixepoch()-300)) limit 1000) returning id', [$max_lifetime]);

        return count($rows);
    }

    /** @return array{payload: string, last_activity: int}|null */
    private function refreshEquivalentSession(string $id, string $data, int $lastActivity): ?array
    {
        $current = $this->query('select payload,last_activity from sessions where id=?', [$id])[0] ?? null;
        if (! is_array($current) || ! is_string($current['payload'] ?? null) || ! is_int($current['last_activity'] ?? null)) {
            return null;
        }
        $stored = base64_decode($current['payload'], true);
        if ($stored === false) {
            return null;
        }
        if (config('session.encrypt') === true) {
            try {
                $encrypter = app(Encrypter::class);
                $stored = $encrypter->decrypt($stored);
                $data = $encrypter->decrypt($data);
            } catch (DecryptException) {
                return null;
            }
        }
        if (! is_string($stored) || ! is_string($data) || ! hash_equals($stored, $data)) {
            return null;
        }
        $rows = $this->query('update sessions set last_activity=max(last_activity,?) where id=? and payload=? and last_activity=? and last_activity>unixepoch()-? returning payload,last_activity', [$lastActivity, $id, $current['payload'], $current['last_activity'], $this->lifetime * 60]);
        $saved = $rows[0] ?? null;
        if (! is_array($saved) || ! is_string($saved['payload'] ?? null) || ! is_int($saved['last_activity'] ?? null)) {
            return null;
        }

        return ['payload' => $saved['payload'], 'last_activity' => $saved['last_activity']];
    }

    /**
     * @param  list<scalar|null>  $params
     * @return list<array<string, scalar|null>>
     */
    private function query(string $sql, array $params): array
    {
        return $this->client->rows($sql, $params);
    }
}
