<?php

declare(strict_types=1);

use FamilyFunds\D1Staging\D1ProductionMaintenance;
use FamilyFunds\D1Staging\D1RuntimeProfile;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;

if (PHP_SAPI !== 'cli' || getcwd() !== '/var/www/html') {
    throw new RuntimeException('Production maintenance requires its container CLI.');
}
require 'vendor/autoload.php';
require_once __DIR__.'/autoload.php';
$app = require 'bootstrap/app.php';
if (! $app instanceof Application) {
    throw new RuntimeException('Production bootstrap did not return an application.');
}
$app->make(Kernel::class)->bootstrap();
D1RuntimeProfile::assertEnabled();
if (! D1RuntimeProfile::production()) {
    throw new RuntimeException('Production CLI refuses other runtime profiles.');
}
$operation = $argv[1] ?? '';
$maintenance = app(D1ProductionMaintenance::class);
if ($operation === 'install') {
    $maintenance->install();
    $result = ['exit_code' => 0, 'installed' => true];
} elseif ($operation === 'task') {
    $json = getenv('FF_PRODUCTION_TASK');
    if (! is_string($json) || strlen($json) > 8192) {
        throw new RuntimeException('Missing or oversized production task.');
    }
    $task = D1ProductionMaintenance::decodeTask($json);
    $result = $maintenance->execute($task);
} elseif ($operation === 'inspect-migration') {
    $result = $maintenance->inspectMigration();
} else {
    throw new RuntimeException('Unknown production maintenance operation.');
}
echo json_encode($result, JSON_THROW_ON_ERROR)."\n";
