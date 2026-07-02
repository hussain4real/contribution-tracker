<?php

namespace App\Support;

final class HttpMethodOverrideSanitizer
{
    /**
     * Remove malformed HTTP method override input before the request is captured.
     */
    public static function sanitizeGlobals(): void
    {
        self::sanitizeInputBag($_POST);
        self::sanitizeInputBag($_GET);
        self::sanitizeInputBag($_REQUEST);

        if (self::isMalformed($_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE'] ?? null)) {
            unset($_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE']);
        }
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private static function sanitizeInputBag(array &$input): void
    {
        if (self::isMalformed($input['_method'] ?? null)) {
            unset($input['_method']);
        }
    }

    private static function isMalformed(mixed $method): bool
    {
        if (! is_string($method)) {
            return false;
        }

        $method = strtoupper($method);

        return strlen($method) !== strspn($method, 'ABCDEFGHIJKLMNOPQRSTUVWXYZ');
    }
}
