<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

pest()->extend(TestCase::class);

it('serves the runtime-key Livewire script after replacing build-time route and configuration caches', function (): void {
    $directory = storage_path('framework/testing/livewire-runtime-'.Str::uuid());
    File::ensureDirectoryExists($directory, 0700);
    $environment = [
        'APP_ENV' => 'production', 'APP_DEBUG' => 'false', 'APP_URL' => 'https://familyfunds.app',
        'APP_KEY' => '', 'APP_CONFIG_CACHE' => $directory.'/config.php',
        'APP_SERVICES_CACHE' => $directory.'/services.php', 'APP_PACKAGES_CACHE' => $directory.'/packages.php',
        'APP_ROUTES_CACHE' => $directory.'/routes.php', 'D1_STAGING_ENABLED' => 'false', 'D1_PRODUCTION_ENABLED' => 'false',
        'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => ':memory:', 'SESSION_DRIVER' => 'array',
        'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'sync', 'NIGHTWATCH_ENABLED' => 'false',
    ];
    try {
        (new Process([PHP_BINARY, 'artisan', 'route:cache', '--no-interaction'], base_path(), $environment))->setTimeout(30)->mustRun();
        $environment['APP_KEY'] = 'base64:'.base64_encode(random_bytes(32));
        preg_match('/mark_startup config_start_ms\n(.*?)mark_startup config_ready_ms/s', File::get(base_path('deployment/d1-proof/container-slim-entrypoint.sh')), $startup);
        expect($startup)->toHaveKey(1);
        if (! isset($startup[1])) {
            throw new RuntimeException('Runtime startup cache commands are missing.');
        }
        (new Process(['sh', '-ec', $startup[1]], base_path(), $environment))->setTimeout(60)->mustRun();
        $code = <<<'PHP'
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$kernel->bootstrap();
$path = Livewire\Mechanisms\HandleRequests\EndpointResolver::scriptPath(minified: true);
$response = $kernel->handle(Illuminate\Http\Request::create('https://familyfunds.app'.$path));
echo json_encode(['status' => $response->getStatusCode(), 'javascript' => str_contains($response->headers->get('Content-Type', ''), 'javascript'), 'cached' => $app->routesAreCached()], JSON_THROW_ON_ERROR);
PHP;
        foreach ([1, 2] as $attempt) {
            $process = (new Process([PHP_BINARY, '-r', $code], base_path(), $environment))->setTimeout(30)->mustRun();
            expect(json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR))->toBe(['status' => 200, 'javascript' => true, 'cached' => true]);
        }
    } finally {
        File::deleteDirectory($directory);
    }
});

it('boots production drivers in two fresh config-cached processes without database traffic or staging activation', function (): void {
    $directory = storage_path('framework/testing/production-boot-'.Str::uuid());
    File::ensureDirectoryExists($directory, 0700);
    $environment = [
        'APP_ENV' => 'production', 'APP_DEBUG' => 'false', 'APP_URL' => 'https://familyfunds.app',
        'APP_KEY' => 'base64:'.base64_encode(random_bytes(32)),
        'APP_CONFIG_CACHE' => $directory.'/config.php', 'APP_SERVICES_CACHE' => $directory.'/services.php', 'APP_PACKAGES_CACHE' => $directory.'/packages.php', 'APP_ROUTES_CACHE' => $directory.'/routes.php',
        'D1_STAGING_ENABLED' => 'false', 'D1_PRODUCTION_ENABLED' => 'true', 'D1_PRODUCTION_DELIVERY_ENABLED' => 'false', 'D1_PRODUCTION_WEBHOOKS_ENABLED' => 'false',
        'D1_DATABASE' => 'familyfunds-production', 'D1_URL' => 'https://familyfunds-production.d1.internal', 'D1_TOKEN' => 'internal-binding-fixture-marker',
        'R2_BUCKET' => 'familyfunds-production-private', 'R2_ACCESS_KEY_ID' => 'synthetic-fixture-key', 'R2_SECRET_ACCESS_KEY' => 'synthetic-fixture-secret',
        'DB_CONNECTION' => 'd1', 'CACHE_STORE' => 'd1', 'SESSION_DRIVER' => 'd1', 'QUEUE_CONNECTION' => 'd1',
        'NIGHTWATCH_ENABLED' => 'false', 'BACKUP_RCLONE_ENABLED' => 'false', 'INERTIA_SSR_ENABLED' => 'false',
    ];
    try {
        $cache = new Process([PHP_BINARY, 'artisan', 'config:cache', '--no-interaction'], base_path(), $environment);
        $cache->setTimeout(30)->mustRun();
        chmod($directory.'/config.php', 0600);
        $code = <<<'PHP'
require 'vendor/autoload.php';
require 'deployment/d1-proof/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
Illuminate\Support\Facades\Http::preventStrayRequests();
$script = Livewire\Mechanisms\HandleRequests\EndpointResolver::scriptPath(minified: true);
$response = $app->make(Illuminate\Contracts\Http\Kernel::class)->handle(Illuminate\Http\Request::create('https://familyfunds.app'.$script));
echo json_encode([
    'script_status' => $response->getStatusCode(),
    'script_is_javascript' => str_contains($response->headers->get('Content-Type', ''), 'javascript'),
    'environment' => app()->environment(), 'cached' => app()->configurationIsCached(),
    'database' => get_class(Illuminate\Support\Facades\DB::connection()),
    'cache' => get_class(Illuminate\Support\Facades\Cache::store()->getStore()),
    'rate_limiter' => get_class(app(Illuminate\Cache\RateLimiter::class)),
    'mfa_limiter_registered' => app(Illuminate\Cache\RateLimiter::class)->limiter('two-factor') !== null,
    'session' => get_class(Illuminate\Support\Facades\Session::driver()->getHandler()),
    'queue' => get_class(Illuminate\Support\Facades\Queue::connection()),
    'bucket' => config('filesystems.disks.local.bucket'), 'staging_enabled' => config('d1-staging.enabled'),
    'mail' => config('mail.default'),
], JSON_THROW_ON_ERROR);
PHP;
        foreach ([1, 2] as $attempt) {
            $process = new Process([PHP_BINARY, '-r', $code], base_path(), $environment);
            $process->setTimeout(30)->mustRun();
            $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            expect($result)->toBe([
                'script_status' => 200, 'script_is_javascript' => true,
                'environment' => 'production', 'cached' => true,
                'database' => 'FamilyFunds\\D1Staging\\D1ProductionConnection',
                'cache' => 'FamilyFunds\\D1Staging\\D1RuntimeCacheStore',
                'rate_limiter' => 'FamilyFunds\\D1Staging\\D1RuntimeRateLimiter',
                'mfa_limiter_registered' => true,
                'session' => 'FamilyFunds\\D1Staging\\D1StagingSessionHandler',
                'queue' => 'FamilyFunds\\D1Staging\\D1StagingQueue',
                'bucket' => 'familyfunds-production-private', 'staging_enabled' => false, 'mail' => 'array',
            ]);
        }
    } finally {
        File::deleteDirectory($directory);
    }
});
