<?php

declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefix = 'FamilyFunds\\D1Staging\\';
    if (! str_starts_with($class, $prefix)) {
        return;
    }
    $name = substr($class, strlen($prefix));
    if (! preg_match('/^[A-Za-z][A-Za-z0-9_]*$/D', $name)) {
        return;
    }
    $file = __DIR__.'/'.$name.'.php';
    if (is_file($file)) {
        require_once $file;
    }
});
