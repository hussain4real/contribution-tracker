<?php

declare(strict_types=1);

namespace FamilyFunds\D1Staging;

use Illuminate\Cache\Lock;
use InvalidArgumentException;

final class D1RuntimeCacheLock extends Lock
{
    public function __construct(private readonly D1RuntimeClient $client, string $name, int $seconds, ?string $owner = null)
    {
        parent::__construct($name, $seconds === 0 ? 86400 : $seconds, $owner);
    }

    public function acquire(): bool
    {
        return $this->client->rows('insert into d1_runtime_cache_locks(key,owner,expires_at) values(?,?,unixepoch()+?) on conflict(key) do update set owner=excluded.owner,expires_at=excluded.expires_at where d1_runtime_cache_locks.expires_at<=unixepoch() returning key', [$this->name, $this->owner, $this->seconds]) !== [];
    }

    public function release(): bool
    {
        return $this->client->rows('delete from d1_runtime_cache_locks where key=? and owner=? returning key', [$this->name, $this->owner]) !== [];
    }

    public function forceRelease(): void
    {
        $this->client->rows('delete from d1_runtime_cache_locks where key=?', [$this->name]);
    }

    public function refresh(mixed $seconds = null): bool
    {
        $seconds ??= $this->seconds;
        if ($seconds < 1) {
            throw new InvalidArgumentException('D1 lock renewal requires a positive TTL.');
        }

        return $this->client->rows('update d1_runtime_cache_locks set expires_at=unixepoch()+? where key=? and owner=? and expires_at>unixepoch() returning key', [$seconds, $this->name, $this->owner]) !== [];
    }

    protected function getCurrentOwner(): ?string
    {
        $value = $this->client->rows('select owner from d1_runtime_cache_locks where key=? and expires_at>unixepoch()', [$this->name])[0]['owner'] ?? null;

        return is_string($value) ? $value : null;
    }
}
