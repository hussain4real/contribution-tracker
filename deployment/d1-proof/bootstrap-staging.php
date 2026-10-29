<?php

declare(strict_types=1);

require dirname(__DIR__, 2).'/vendor/autoload.php';
$secrets = json_decode(file_get_contents(dirname(__DIR__, 2).'/storage/app/private/d1-staging/leased-proof-secrets.json'), true, flags: JSON_THROW_ON_ERROR);
foreach (['D1_STAGING_TOKEN' => $secrets['scale_sql_token'], 'APP_KEY' => $secrets['app_key']] as $name => $value) {
    putenv($name.'='.$value);
    $_ENV[$name] = $value;
    $_SERVER[$name] = $value;
}
