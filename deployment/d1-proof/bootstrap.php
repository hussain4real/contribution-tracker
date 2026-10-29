<?php

declare(strict_types=1);

require dirname(__DIR__, 2).'/vendor/autoload.php';

$environmentFile = dirname(__DIR__, 2).'/.env';
$values = is_file($environmentFile) ? Dotenv\Dotenv::parse(file_get_contents($environmentFile)) : [];

foreach (['D1_PROOF_URL', 'D1_PROOF_TOKEN'] as $key) {
    if (getenv($key) === false && isset($values[$key])) {
        putenv($key.'='.$values[$key]);
        $_ENV[$key] = $values[$key];
    }
}
