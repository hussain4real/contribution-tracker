<?php

declare(strict_types=1);

namespace FamilyFunds\D1Staging;

use Illuminate\Cache\RateLimiter;
use Illuminate\Cache\Repository;
use LogicException;

final class D1RuntimeRateLimiter extends RateLimiter
{
    private readonly D1RuntimeCacheStore $store;

    public function __construct(Repository $cache, ?RateLimiter $previous = null)
    {
        parent::__construct($cache);
        $store = $cache->getStore();
        if (! $store instanceof D1RuntimeCacheStore) {
            throw new LogicException('The D1 rate limiter requires its durable cache store.');
        }
        $this->store = $store;
        if ($previous !== null) {
            $this->limiters = $previous->limiters;
        }
    }

    public function increment(mixed $key, mixed $decaySeconds = 60, mixed $amount = 1): int
    {
        $seconds = $this->secondsUntil($decaySeconds);
        if ($seconds <= 0 || ! is_int($amount)) {
            return parent::increment($key, $decaySeconds, $amount);
        }

        return $this->store->incrementRateLimit($this->cleanRateLimiterKey($key), $seconds, $this->availableAt($decaySeconds), $amount);
    }

    public function clear(mixed $key): void
    {
        $this->store->clearRateLimit($this->cleanRateLimiterKey($key));
    }
}
