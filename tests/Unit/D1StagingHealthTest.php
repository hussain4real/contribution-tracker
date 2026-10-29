<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

pest()->extend(TestCase::class);

it('checks PHP startup configuration without booting Laravel or exposing credentials', function (?array $configuration, int $status): void {
    $root = storage_path('framework/testing/d1-health-'.Str::uuid());
    File::ensureDirectoryExists($root.'/deployment/d1-proof');
    File::ensureDirectoryExists($root.'/bootstrap/cache');
    File::copy(base_path('deployment/d1-proof/container-health.php'), $root.'/deployment/d1-proof/container-health.php');
    if ($configuration !== null) {
        File::put($root.'/bootstrap/cache/config.php', '<?php return '.var_export($configuration, true).';');
    }
    try {
        $process = new Process([PHP_BINARY, '-r', 'require $argv[1]; echo ":".http_response_code();', $root.'/deployment/d1-proof/container-health.php']);
        $process->mustRun();
        expect($process->getOutput())->toBe(($status === 200 ? 'ready' : 'starting').':'.$status)
            ->and($process->getErrorOutput())->toBe('');
    } finally {
        File::deleteDirectory($root);
    }
})->with([
    'web staging' => [['app' => ['env' => 'd1-staging'], 'd1-staging' => ['enabled' => true, 'database' => 'familyfunds-d1-web-staging', 'token' => 'fixture-secret']], 200],
    'synthetic scale lab' => [['app' => ['env' => 'd1-staging'], 'd1-staging' => ['enabled' => true, 'database' => 'familyfunds-d1-scale-lab', 'token' => 'fixture-secret']], 200],
    'production environment' => [['app' => ['env' => 'production'], 'd1-staging' => ['enabled' => true, 'database' => 'familyfunds-d1-web-staging', 'token' => 'fixture-secret']], 503],
    'production candidate' => [['app' => ['env' => 'production', 'debug' => false], 'd1-staging' => ['enabled' => false], 'd1-production' => ['enabled' => true, 'database' => 'familyfunds-production', 'url' => 'https://familyfunds-production.d1.internal', 'token' => 'fixture-marker'], 'database' => ['default' => 'd1'], 'cache' => ['default' => 'd1'], 'session' => ['encrypt' => true]], 200],
    'production refuses staging target' => [['app' => ['env' => 'production', 'debug' => false], 'd1-production' => ['enabled' => true, 'database' => 'familyfunds-d1-web-staging', 'url' => 'https://familyfunds-production.d1.internal', 'token' => 'fixture-marker'], 'database' => ['default' => 'd1'], 'cache' => ['default' => 'd1'], 'session' => ['encrypt' => true]], 503],
    'mixed staging and production flags' => [['app' => ['env' => 'd1-staging'], 'd1-production' => ['enabled' => true], 'd1-staging' => ['enabled' => true, 'database' => 'familyfunds-d1-web-staging', 'token' => 'fixture-secret']], 503],
    'unapproved database' => [['app' => ['env' => 'd1-staging'], 'd1-staging' => ['enabled' => true, 'database' => 'production', 'token' => 'fixture-secret']], 503],
    'missing credentials' => [['app' => ['env' => 'd1-staging'], 'd1-staging' => ['enabled' => true, 'database' => 'familyfunds-d1-web-staging']], 503],
    'missing configuration' => [null, 503],
]);
