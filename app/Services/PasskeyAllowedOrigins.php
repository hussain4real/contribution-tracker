<?php

declare(strict_types=1);

namespace App\Services;

final class PasskeyAllowedOrigins
{
    /**
     * Build the exact origins allowed to complete WebAuthn passkey ceremonies.
     *
     * @return list<string>
     */
    public static function fromAppUrl(string $appUrl, mixed $configuredOrigins = null): array
    {
        $origins = [];
        $appOrigin = self::originFromUrl($appUrl);

        if ($appOrigin !== null) {
            $origins[] = $appOrigin;
        }

        $localHttpsOrigin = self::localHttpsOrigin($appUrl);

        if ($localHttpsOrigin !== null) {
            $origins[] = $localHttpsOrigin;
        }

        array_push($origins, ...self::configuredOrigins($configuredOrigins));

        return array_values(array_unique($origins));
    }

    private static function localHttpsOrigin(string $appUrl): ?string
    {
        $parts = self::urlParts($appUrl);

        if ($parts === null) {
            return null;
        }

        $scheme = strtolower($parts['scheme'] ?? '');
        $host = $parts['host'] ?? null;

        if ($scheme !== 'http' || ! is_string($host) || ! self::isLocalDevelopmentHost($host)) {
            return null;
        }

        return 'https://'.self::formatHost(strtolower($host)).self::portSuffix($parts);
    }

    /**
     * @return list<string>
     */
    private static function configuredOrigins(mixed $configuredOrigins): array
    {
        if ($configuredOrigins === null || $configuredOrigins === false) {
            return [];
        }

        if (is_string($configuredOrigins)) {
            $configuredOrigins = explode(',', $configuredOrigins);
        }

        if (! is_array($configuredOrigins)) {
            return [];
        }

        $origins = [];

        foreach ($configuredOrigins as $origin) {
            if (! is_scalar($origin)) {
                continue;
            }

            $normalisedOrigin = self::originFromUrl((string) $origin);

            if ($normalisedOrigin !== null) {
                $origins[] = $normalisedOrigin;
            }
        }

        return $origins;
    }

    private static function originFromUrl(string $url): ?string
    {
        $url = trim($url);

        if ($url === '') {
            return null;
        }

        $parts = self::urlParts($url);

        if ($parts === null) {
            return null;
        }

        $scheme = strtolower($parts['scheme'] ?? '');
        $host = $parts['host'] ?? null;

        if (! in_array($scheme, ['http', 'https'], true) || ! is_string($host)) {
            return null;
        }

        return $scheme.'://'.self::formatHost(strtolower($host)).self::portSuffix($parts);
    }

    private static function isLocalDevelopmentHost(string $host): bool
    {
        $host = strtolower(trim($host, '[]'));

        return $host === 'localhost'
            || $host === '::1'
            || $host === '127.0.0.1'
            || str_starts_with($host, '127.')
            || str_ends_with($host, '.localhost')
            || str_ends_with($host, '.test');
    }

    private static function formatHost(string $host): string
    {
        $host = trim($host, '[]');

        if (str_contains($host, ':') && filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            return '['.$host.']';
        }

        return $host;
    }

    /**
     * @param  array{port?: int}  $parts
     */
    private static function portSuffix(array $parts): string
    {
        return isset($parts['port']) ? ':'.$parts['port'] : '';
    }

    /**
     * @return array{scheme?: string, host?: string, port?: int}|null
     */
    private static function urlParts(string $url): ?array
    {
        $parts = parse_url($url);

        if ($parts === false) {
            return null;
        }

        /** @var array{scheme?: string, host?: string, port?: int} $parts */
        return $parts;
    }
}
