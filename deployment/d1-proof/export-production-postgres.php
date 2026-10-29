<?php

declare(strict_types=1);

use FamilyFunds\D1Staging\D1PostgresSnapshot;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Encryption\Encrypter;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;

$sourceRoot = $argv[1] ?? '';
$keyFile = $argv[2] ?? '';
$output = $argv[3] ?? '';
$privateRoot = '/var/www/familyfunds-d1-staging/storage/app/private/prepared-production-runtime';
$preserveCurrentKey = $keyFile === '--preserve-current-key';
$validOutput = $preserveCurrentKey
    ? preg_match('#^'.preg_quote($privateRoot.'/source-current-', '#').'[0-9]{8}T[0-9]{6}Z\.json$#D', $output) === 1
    : $output === $privateRoot.'/source.json';
if (PHP_SAPI !== 'cli' || ! function_exists('posix_geteuid') || posix_geteuid() !== 0
    || $sourceRoot !== '/var/www/contribution-tracker'
    || (! $preserveCurrentKey && $keyFile !== $privateRoot.'/app-key.json') || ! $validOutput
    || is_link($privateRoot) || realpath($privateRoot) !== $privateRoot
    || fileowner($privateRoot) !== 0 || (fileperms($privateRoot) & 0777) !== 0700
    || (! $preserveCurrentKey && (is_link($keyFile) || fileowner($keyFile) !== 0 || (fileperms($keyFile) & 0777) !== 0600))) {
    throw new RuntimeException('Production export requires the root-only prepared Hetzner paths.');
}
umask(0077);
chdir($sourceRoot);
require 'vendor/autoload.php';
require_once __DIR__.'/D1PostgresSnapshot.php';
require_once __DIR__.'/D1StagingCipherMigration.php';
$app = require 'bootstrap/app.php';
if (! $app instanceof Application) {
    throw new RuntimeException('Unexpected production application bootstrap.');
}
$app->make(Kernel::class)->bootstrap();
$sourceEncrypter = app('encrypter');
if (! app()->environment('production')) {
    throw new RuntimeException('Unexpected production source environment.');
}
$targetEncrypter = $sourceEncrypter;
if (! $preserveCurrentKey) {
    $keyJson = file_get_contents($keyFile);
    if ($keyJson === false) {
        throw new RuntimeException('Could not read the prepared target key.');
    }
    $targetConfiguration = json_decode($keyJson, true, flags: JSON_THROW_ON_ERROR);
    $encoded = is_array($targetConfiguration) ? ($targetConfiguration['app_key'] ?? null) : null;
    $bytes = is_string($encoded) && str_starts_with($encoded, 'base64:') ? base64_decode(substr($encoded, 7), true) : false;
    if (! is_string($bytes) || strlen($bytes) !== 32) {
        throw new RuntimeException('Invalid prepared production target key.');
    }
    $targetEncrypter = new Encrypter($bytes, 'AES-256-CBC');
}
$snapshot = D1PostgresSnapshot::capture(DB::connection()->getPdo(), $sourceEncrypter, $targetEncrypter);
$tables = $snapshot['tables'] ?? null;
if (! is_array($tables)) {
    throw new RuntimeException('Invalid snapshot table collection.');
}
$rowCount = 0;
foreach ($tables as $table) {
    if (! is_array($table) || ! isset($table['rows']) || ! is_array($table['rows'])) {
        throw new RuntimeException('Invalid snapshot table rows.');
    }
    $rowCount += count($table['rows']);
}
$json = json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
$handle = fopen($output, 'x');
if ($handle === false) {
    throw new RuntimeException('Production export refuses to replace an existing snapshot.');
}
try {
    $remaining = $json;
    while ($remaining !== '') {
        $written = fwrite($handle, $remaining);
        if ($written === false || $written === 0) {
            throw new RuntimeException('Production snapshot write failed.');
        }
        $remaining = substr($remaining, $written);
    }
    fflush($handle);
} finally {
    fclose($handle);
}
echo json_encode(['status' => 'prepared-on-hetzner', 'sha256' => hash('sha256', $json), 'source_key_exported' => false, 'target_key_exported' => false, 'current_key_preserved' => $preserveCurrentKey, 'file_mode' => '0600', 'purpose' => $snapshot['purpose'], 'tables' => count($tables), 'rows' => $rowCount, 'encrypted_fields' => $snapshot['preserved_production_mfa']], JSON_THROW_ON_ERROR)."\n";
