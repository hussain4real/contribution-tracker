<?php

declare(strict_types=1);

namespace FamilyFunds\D1Staging;

use Illuminate\Http\Client\ConnectionException;
use RuntimeException;
use SessionHandlerInterface;

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
        $rows = $this->query('select payload,last_activity from sessions where id=? and last_activity>unixepoch()-?', [$id, $this->lifetime * 60]);
        if ($rows === []) {
            return '';
        }
        $payload = $rows[0]['payload'];
        $lastActivity = $rows[0]['last_activity'];
        if (! is_string($payload) || ! is_int($lastActivity)) {
            throw new RuntimeException('Invalid staging session payload.');
        }
        $decoded = base64_decode($payload, true);
        if ($decoded === false) {
            throw new RuntimeException('Corrupt staging session payload.');
        }

        $this->readSessions[$id] = ['payload' => $payload, 'last_activity' => $lastActivity];

        return $decoded;
    }

    public function write(string $id, string $data): bool
    {
        $payload = base64_encode($data);
        $lastActivity = now()->getTimestamp();
        try {
            $this->query('insert into sessions(id,payload,last_activity) values (?,?,?) on conflict(id) do update set payload=excluded.payload,last_activity=excluded.last_activity', [$id, $payload, $lastActivity]);
        } catch (ConnectionException $exception) {
            $client = new D1RuntimeClient($this->url, $this->token, $this->database, timeout: 5);
            $rows = $client->rows('select payload,last_activity from sessions where id=?', [$id]);
            $current = $rows[0] ?? null;
            if ($current !== ['payload' => $payload, 'last_activity' => $lastActivity]) {
                $previous = $this->readSessions[$id] ?? null;
                if ($previous === null || $current !== $previous || $previous['last_activity'] <= now()->getTimestamp() - $this->lifetime * 60) {
                    throw $exception;
                }
                $saved = $client->rows('update sessions set payload=?,last_activity=? where id=? and payload=? and last_activity=? returning id', [$payload, $lastActivity, $id, $previous['payload'], $previous['last_activity']]);
                if ($saved !== [['id' => $id]]) {
                    throw $exception;
                }
            }
        }
        $this->readSessions[$id] = ['payload' => $payload, 'last_activity' => $lastActivity];

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
        $rows = $this->query('delete from sessions where id in (select id from sessions where last_activity<=unixepoch()-? limit 1000) returning id', [$max_lifetime]);

        return count($rows);
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
