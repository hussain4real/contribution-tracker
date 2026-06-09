<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\PlatformPlan;
use App\Support\PlatformPlanCatalog;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Fortify\Features;

class PricingController extends Controller
{
    public function __invoke(): Response
    {
        $plans = PlatformPlan::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get()
            ->map(fn (PlatformPlan $plan): array => PlatformPlanCatalog::subscriptionCard($plan));

        return Inertia::render('Pricing', [
            'plans' => $plans,
            'available_features' => PlatformPlanCatalog::featureLabels(),
            'canRegister' => Features::enabled(Features::registration()),
        ]);
    }
}
