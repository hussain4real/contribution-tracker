<?php

declare(strict_types=1);

use App\Providers\AppServiceProvider;
use App\Providers\Filament\AdminPanelProvider;
use App\Providers\FortifyServiceProvider;

$providers = [
    AppServiceProvider::class,
    AdminPanelProvider::class,
    FortifyServiceProvider::class,
];

if (config('d1-staging.enabled')) {
    require_once __DIR__.'/../deployment/d1-proof/autoload.php';
    $providers[] = 'FamilyFunds\\D1Staging\\D1StagingServiceProvider';
}

if (config('d1-production.enabled')) {
    require_once __DIR__.'/../deployment/d1-proof/autoload.php';
    $providers[] = 'FamilyFunds\\D1Staging\\D1ProductionServiceProvider';
}

return $providers;
