<?php

declare(strict_types=1);

namespace App\Filament\Resources\PlatformPlans\Pages;

use App\Filament\Resources\PlatformPlans\PlatformPlanResource;
use Filament\Resources\Pages\CreateRecord;

class CreatePlatformPlan extends CreateRecord
{
    protected static string $resource = PlatformPlanResource::class;
}
