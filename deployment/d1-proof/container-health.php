<?php

declare(strict_types=1);

$path = dirname(__DIR__, 2).'/bootstrap/cache/config.php';
$configuration = is_file($path) ? require $path : [];
$staging = $configuration['d1-staging'] ?? [];
$replacement = 'familyfunds-d1-staging-clone-20261104t044139z';
$replacementReady = ($staging['database'] ?? null) === $replacement
    && ($staging['url'] ?? null) === 'https://familyfunds-d1-replacement-sql-nov04.familyfunds-lab.workers.dev'
    && ($staging['r2']['admission'] ?? null) === '1'
    && ($staging['r2']['bucket'] ?? null) === $replacement.'-files';
$ready = ($configuration['app']['env'] ?? null) === 'd1-staging'
    && ($configuration['d1-production']['enabled'] ?? false) === false
    && ($staging['enabled'] ?? false) === true
    && ($replacementReady || in_array($staging['database'] ?? null, ['familyfunds-d1-web-staging', 'familyfunds-d1-scale-lab'], true))
    && is_string($staging['token'] ?? null) && $staging['token'] !== '';
$production = $configuration['d1-production'] ?? [];
$ready = $ready || (($configuration['app']['env'] ?? null) === 'production'
    && ($configuration['app']['debug'] ?? true) === false
    && ($staging['enabled'] ?? false) === false
    && ($production['enabled'] ?? false) === true
    && ($production['database'] ?? null) === 'familyfunds-production'
    && ($production['url'] ?? null) === 'https://familyfunds-production.d1.internal'
    && is_string($production['token'] ?? null) && $production['token'] !== ''
    && ($configuration['database']['default'] ?? null) === 'd1'
    && ($configuration['cache']['default'] ?? null) === 'd1'
    && ($configuration['session']['encrypt'] ?? false) === true);

http_response_code($ready ? 200 : 503);
if ($ready && getenv('FF_STARTUP_PROFILE') === '1') {
    $marker = dirname(__DIR__, 2).'/bootstrap/cache/startup-ready.json';
    if (! is_file($marker)) {
        file_put_contents($marker, json_encode(['php_ready_ms' => microtime(true) * 1000], JSON_THROW_ON_ERROR));
        chmod($marker, 0600);
    }
}
header('Content-Type: text/plain');
echo $ready ? 'ready' : 'starting';
