<?php

declare(strict_types=1);

use FamilyFunds\D1Staging\D1StagingConnection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

pest()->extend(TestCase::class);

it('isolates the maintenance CLI from cached web callbacks and still rejects outbound delivery', function (bool $deliveryEnabled): void {
    require_once base_path('deployment/d1-proof/autoload.php');
    $database = D1StagingConnection::REPLACEMENT_DATABASE;
    $directory = storage_path('framework/testing/maintenance-'.Str::uuid());
    File::ensureDirectoryExists($directory, 0700);
    $environment = [
        'APP_ENV' => 'd1-staging', 'APP_KEY' => 'base64:'.base64_encode(random_bytes(32)),
        'APP_CONFIG_CACHE' => $directory.'/config.php', 'APP_DEBUG' => 'false', 'LOG_CHANNEL' => 'stderr',
        'MAIL_MAILER' => 'array', 'D1_STAGING_ENABLED' => 'true', 'D1_STAGING_DATABASE' => $database,
        'D1_STAGING_URL' => D1StagingConnection::TARGETS[$database], 'D1_STAGING_TOKEN' => str_repeat('x', 32),
        'D1_STAGING_R2_ADMISSION' => '1', 'D1_STAGING_R2_BUCKET' => $database.'-files',
        'D1_STAGING_R2_ACCESS_KEY_ID' => 'synthetic', 'D1_STAGING_R2_SECRET_ACCESS_KEY' => 'synthetic',
        'D1_STAGING_PAYSTACK_WEBHOOK_SIGNING_SECRET' => 'whsec_synthetic', 'D1_STAGING_META_APP_SECRET' => str_repeat('f', 32),
        'D1_STAGING_DELIVERY_ENABLED' => 'false', 'D1_STAGING_WEBHOOKS_ENABLED' => 'true',
        'D1_STAGING_RELAY_TOKEN' => 'synthetic-callback-credential', 'NIGHTWATCH_ENABLED' => 'false',
    ];
    try {
        (new Process([PHP_BINARY, 'artisan', 'config:cache', '--no-interaction'], base_path(), $environment))->mustRun();
        $cache = require $environment['APP_CONFIG_CACHE'];
        expect($cache['d1-staging']['webhooks']['enabled'])->toBeTrue();
        if ($deliveryEnabled) {
            $cache['d1-staging']['delivery']['enabled'] = true;
            File::put($environment['APP_CONFIG_CACHE'], '<?php return '.var_export($cache, true).';');
        }
        $digest = hash_file('sha256', $environment['APP_CONFIG_CACHE']);
        $command = new Process([PHP_BINARY, 'deployment/d1-proof/run-staging.php', 'scheduled-maintenance-proof',
            $directory.'/missing.json', 'replacement', 'reports', '1', '2020-01-25T08:00:00.000Z'], base_path(), $environment);
        $command->run();
        if ($deliveryEnabled) {
            expect($command->isSuccessful())->toBeFalse();
        }
        expect($command->getOutput().$command->getErrorOutput())->toContain($deliveryEnabled
                ? 'Replacement maintenance requires the isolated cached application configuration.'
                : 'Report maintenance requires the synthetic report family.')
            ->and(hash_file('sha256', $environment['APP_CONFIG_CACHE']))->toBe($digest);
    } finally {
        File::deleteDirectory($directory);
    }
})->with([false, true]);
