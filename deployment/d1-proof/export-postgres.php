<?php

declare(strict_types=1);
use FamilyFunds\D1Staging\D1PostgresSnapshot;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\DB;

$root = $argv[1] ?? '';
if (PHP_SAPI !== 'cli' || ! in_array($root, ['/var/www/contribution-tracker', '/var/www/contribution-tracker-staging'], true)) {
    throw new RuntimeException('Export requires an explicitly approved source checkout.');
}
chdir($root);
require 'vendor/autoload.php';
require_once __DIR__.'/D1StagingCipherMigration.php';
require_once __DIR__.'/D1PostgresSnapshot.php';
$app = require 'bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$connection = DB::connection();
if ($connection->getDriverName() !== 'pgsql') {
    throw new RuntimeException('The migration source must be PostgreSQL.');
}
$encrypter = app('encrypter');
if (! $encrypter instanceof Encrypter) {
    throw new RuntimeException('Unexpected source encryption service.');
}
echo json_encode(D1PostgresSnapshot::capture($connection->getPdo(), $encrypter), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
