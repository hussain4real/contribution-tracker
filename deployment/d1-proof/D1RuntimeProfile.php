<?php

declare(strict_types=1);

namespace FamilyFunds\D1Staging;

use RuntimeException;

final class D1RuntimeProfile
{
    public const PRODUCTION_DATABASE = 'familyfunds-production';

    public const PRODUCTION_URL = 'https://familyfunds-production.d1.internal';

    public const PRODUCTION_BUCKET = 'familyfunds-production-private';

    public static function production(): bool
    {
        return config('d1-production.enabled') === true;
    }

    public static function key(string $suffix): string
    {
        return (self::production() ? 'd1-production.' : 'd1-staging.').$suffix;
    }

    public static function connection(): string
    {
        return self::production() ? 'd1' : 'd1-staging';
    }

    public static function identifierReservation(): int
    {
        return self::production() ? 16384 : 1024;
    }

    public static function assertEnabled(): void
    {
        if (self::production()) {
            if (! app()->environment('production') || config('d1-staging.enabled') || config('app.debug')) {
                throw new RuntimeException('Production D1 requires its own production profile, with staging and debug disabled.');
            }
        } elseif (! app()->environment(['testing', 'd1-staging']) || ! config('d1-staging.enabled')) {
            throw new RuntimeException('D1 staging requires an explicitly isolated database and environment.');
        }
    }

    public static function assertTarget(string $database, string $url, string $token): void
    {
        self::assertEnabled();
        $targets = self::production()
            ? [self::PRODUCTION_DATABASE => self::PRODUCTION_URL]
            : D1StagingConnection::TARGETS;
        if (($targets[$database] ?? null) !== $url || $token === '') {
            throw new RuntimeException('D1 requires an explicitly isolated database and endpoint matching its runtime profile.');
        }
    }
}
