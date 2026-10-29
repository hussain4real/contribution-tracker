<?php

declare(strict_types=1);

namespace FamilyFunds\D1Staging;

use RuntimeException;
use SessionHandlerInterface;

final class D1StagingSessionHandler implements SessionHandlerInterface
{
    private readonly D1RuntimeClient $client;

    public function __construct(private readonly string $url, private readonly string $token, private readonly string $database, private readonly int $lifetime)
    {
        D1RuntimeProfile::assertTarget($database, $url, $token);
        if ($lifetime < 1) {
            throw new RuntimeException('D1 session lifetime must be positive.');
        }
        $this->client = new D1RuntimeClient($url, $token, $database);
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
        $rows = $this->query('select payload from sessions where id=? and last_activity>unixepoch()-?', [$id, $this->lifetime * 60]);
        if ($rows === []) {
            return '';
        }
        $payload = $rows[0]['payload'];
        if (! is_string($payload)) {
            throw new RuntimeException('Invalid staging session payload.');
        }
        $decoded = base64_decode($payload, true);
        if ($decoded === false) {
            throw new RuntimeException('Corrupt staging session payload.');
        }

        return $decoded;
    }

    public function write(string $id, string $data): bool
    {
        $this->query('insert into sessions(id,payload,last_activity) values (?,?,unixepoch()) on conflict(id) do update set payload=excluded.payload,last_activity=excluded.last_activity', [$id, base64_encode($data)]);

        return true;
    }

    public function destroy(string $id): bool
    {
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
