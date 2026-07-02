<?php

declare(strict_types=1);

namespace App\Http;

final class HttpMethodOverrideSanitizer
{
    /**
     * @var array<int, string>
     */
    private const SupportedMethodOverrides = [
        'DELETE',
        'OPTIONS',
        'PATCH',
        'PUT',
    ];

    /**
     * Remove unsupported HTTP method override input before the request is captured.
     */
    public static function sanitizeGlobals(): void
    {
        self::sanitizeInputBag($_POST);
        self::sanitizeInputBag($_GET);
        self::sanitizeInputBag($_REQUEST);

        if (! self::isSupported($_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE'] ?? null)) {
            unset($_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE']);
        }
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private static function sanitizeInputBag(array &$input): void
    {
        if (! array_key_exists('_method', $input)) {
            return;
        }

        if (! self::isSupported($input['_method'])) {
            unset($input['_method']);
        }
    }

    private static function isSupported(mixed $method): bool
    {
        if (! is_string($method)) {
            return false;
        }

        return in_array(strtoupper($method), self::SupportedMethodOverrides, true);
    }
}
