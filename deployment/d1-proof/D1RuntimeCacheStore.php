<?php

declare(strict_types=1);

namespace FamilyFunds\D1Staging;

use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Store;
use InvalidArgumentException;
use RuntimeException;

final class D1RuntimeCacheStore implements LockProvider, Store
{
    public static function install(D1RuntimeClient $client): void
    {
        $client->rows('create table if not exists d1_runtime_cache(key text primary key,payload text,number integer,expires_at integer not null)');
        $client->rows('create table if not exists d1_runtime_cache_locks(key text primary key,owner text not null,expires_at integer not null)');
    }

    public function __construct(private readonly D1RuntimeClient $client, private readonly string $prefix = 'familyfunds-production:')
    {
        if ($prefix === '') {
            throw new InvalidArgumentException('D1 cache requires a nonempty namespace.');
        }
    }

    public function get(mixed $key): mixed
    {
        $row = $this->client->rows('select payload,number from d1_runtime_cache where key=? and (expires_at=0 or expires_at>unixepoch())', [$this->key($key)])[0] ?? null;
        if ($row === null) {
            return null;
        }
        if (is_int($row['number'])) {
            return $row['number'];
        }
        $payload = $row['payload'];
        $decoded = is_string($payload) ? base64_decode($payload, true) : false;
        if ($decoded === false) {
            throw new RuntimeException('Invalid durable cache payload.');
        }

        return unserialize($decoded);
    }

    /**
     * @param  array<array-key, mixed>  $keys
     * @return array<string, mixed>
     */
    public function many(array $keys): array
    {
        $result = [];
        foreach ($keys as $key) {
            if (! is_string($key)) {
                throw new InvalidArgumentException('D1 cache keys must be strings.');
            }
            $result[$key] = $this->get($key);
        }

        return $result;
    }

    public function put(mixed $key, mixed $value, mixed $seconds): bool
    {
        if ($seconds <= 0) {
            return $this->forget($key);
        }

        return $this->write($key, $value, $seconds, false);
    }

    public function add(mixed $key, mixed $value, mixed $seconds): bool
    {
        if ($seconds <= 0) {
            return false;
        }

        return $this->write($key, $value, $seconds, true);
    }

    /** @param array<array-key, mixed> $values */
    public function putMany(array $values, mixed $seconds): bool
    {
        foreach ($values as $key => $value) {
            $this->put($key, $value, $seconds);
        }

        return true;
    }

    public function increment(mixed $key, mixed $value = 1): int|false
    {
        if (! is_int($value)) {
            throw new InvalidArgumentException('D1 cache increments must be integers.');
        }
        $row = $this->client->rows('update d1_runtime_cache set number=number+? where key=? and number is not null and (expires_at=0 or expires_at>unixepoch()) returning number', [$value, $this->key($key)])[0] ?? null;

        return isset($row['number']) && is_int($row['number']) ? $row['number'] : false;
    }

    public function decrement(mixed $key, mixed $value = 1): int|false
    {
        if (! is_int($value)) {
            throw new InvalidArgumentException('D1 cache decrements must be integers.');
        }

        return $this->increment($key, -$value);
    }

    public function forever(mixed $key, mixed $value): bool
    {
        return $this->write($key, $value, 0, false);
    }

    public function incrementRateLimit(string $key, int $seconds, int $resetAt, int $amount): int
    {
        if ($seconds < 1) {
            throw new InvalidArgumentException('A rate-limit window must be positive.');
        }
        $timer = 'insert into d1_runtime_cache(key,payload,number,expires_at) values(?,null,?,unixepoch()+?) on conflict(key) do update set payload=null,number=excluded.number,expires_at=excluded.expires_at where d1_runtime_cache.expires_at<>0 and d1_runtime_cache.expires_at<=unixepoch()';
        $counter = 'insert into d1_runtime_cache(key,payload,number,expires_at) values(?,null,?,unixepoch()+?) on conflict(key) do update set payload=null,number=case when d1_runtime_cache.expires_at<>0 and d1_runtime_cache.expires_at<=unixepoch() then excluded.number else d1_runtime_cache.number+excluded.number end,expires_at=case when (d1_runtime_cache.expires_at<>0 and d1_runtime_cache.expires_at<=unixepoch()) or d1_runtime_cache.number=0 then excluded.expires_at else d1_runtime_cache.expires_at end where d1_runtime_cache.number is not null or (d1_runtime_cache.expires_at<>0 and d1_runtime_cache.expires_at<=unixepoch()) returning number';
        $results = $this->client->request('/batch', ['batch' => [
            ['sql' => $timer, 'params' => [$this->key($key.':timer'), $resetAt, $seconds]],
            ['sql' => $counter, 'params' => [$this->key($key), $amount, $seconds]],
        ]]);
        $hits = $results[1]['results'][0]['number'] ?? null;
        if (! is_int($hits)) {
            throw new RuntimeException('Invalid durable rate-limit counter.');
        }

        return $hits;
    }

    public function clearRateLimit(string $key): void
    {
        $this->client->rows('delete from d1_runtime_cache where key in (?,?)', [$this->key($key), $this->key($key.':timer')]);
    }

    public function touch(mixed $key, mixed $seconds): bool
    {
        if ($seconds < 1) {
            return $this->forget($key);
        }

        return $this->client->rows('update d1_runtime_cache set expires_at=unixepoch()+? where key=? and (expires_at=0 or expires_at>unixepoch()) returning key', [$seconds, $this->key($key)]) !== [];
    }

    public function forget(mixed $key): bool
    {
        $this->client->rows('delete from d1_runtime_cache where key=?', [$this->key($key)]);

        return true;
    }

    public function flush(): bool
    {
        $this->client->rows('delete from d1_runtime_cache where substr(key,1,length(?))=?', [$this->prefix, $this->prefix]);

        return true;
    }

    public function getPrefix(): string
    {
        return $this->prefix;
    }

    public function lock(mixed $name, mixed $seconds = 0, mixed $owner = null): D1RuntimeCacheLock
    {
        if ($seconds < 0) {
            throw new InvalidArgumentException('Invalid D1 lock configuration.');
        }

        return new D1RuntimeCacheLock($this->client, $this->key($name), $seconds, $owner);
    }

    public function restoreLock(mixed $name, mixed $owner): D1RuntimeCacheLock
    {
        return $this->lock($name, 0, $owner);
    }

    private function write(mixed $key, mixed $value, mixed $seconds, bool $onlyAbsent): bool
    {
        if (! is_int($seconds) || $seconds < 0) {
            throw new InvalidArgumentException('D1 cache TTL must be a nonnegative integer.');
        }
        $sql = 'insert into d1_runtime_cache(key,payload,number,expires_at) values(?,?,?,case when ?=0 then 0 else unixepoch()+? end) on conflict(key) do update set payload=excluded.payload,number=excluded.number,expires_at=excluded.expires_at';
        if ($onlyAbsent) {
            $sql .= ' where d1_runtime_cache.expires_at<>0 and d1_runtime_cache.expires_at<=unixepoch()';
        }
        $rows = $this->client->rows($sql.' returning key', [$this->key($key), is_int($value) ? null : base64_encode(serialize($value)), is_int($value) ? $value : null, $seconds, $seconds]);

        return $rows !== [];
    }

    private function key(mixed $key): string
    {
        if (! is_string($key) || $key === '') {
            throw new InvalidArgumentException('D1 cache keys must be nonempty strings.');
        }

        return $this->prefix.$key;
    }
}
