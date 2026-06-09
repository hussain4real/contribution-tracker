<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\PlatformPlan;
use App\Support\PlatformPlanCatalog;
use Illuminate\Database\Seeder;

class PlatformPlanSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $plans = [
            [
                'name' => 'Free',
                'slug' => PlatformPlanCatalog::Free,
                'price' => 0,
                'max_members' => 5,
                'features' => [
                    PlatformPlanCatalog::BasicContributions,
                    PlatformPlanCatalog::ManualPayments,
                ],
                'is_active' => true,
                'sort_order' => 0,
            ],
            [
                'name' => 'Family',
                'slug' => PlatformPlanCatalog::Family,
                'price' => 3000,
                'max_members' => 25,
                'features' => [
                    PlatformPlanCatalog::BasicContributions,
                    PlatformPlanCatalog::ManualPayments,
                    PlatformPlanCatalog::OnlinePayments,
                    PlatformPlanCatalog::Reports,
                ],
                'is_active' => true,
                'sort_order' => 1,
            ],
            [
                'name' => 'Growth',
                'slug' => PlatformPlanCatalog::Growth,
                'price' => 7500,
                'max_members' => 75,
                'features' => [
                    PlatformPlanCatalog::BasicContributions,
                    PlatformPlanCatalog::ManualPayments,
                    PlatformPlanCatalog::OnlinePayments,
                    PlatformPlanCatalog::Reports,
                    PlatformPlanCatalog::Exports,
                    PlatformPlanCatalog::AiAssistant,
                ],
                'is_active' => true,
                'sort_order' => 2,
            ],
            [
                'name' => 'Organization',
                'slug' => PlatformPlanCatalog::Organization,
                'price' => 20000,
                'max_members' => 250,
                'features' => [
                    PlatformPlanCatalog::BasicContributions,
                    PlatformPlanCatalog::ManualPayments,
                    PlatformPlanCatalog::OnlinePayments,
                    PlatformPlanCatalog::Reports,
                    PlatformPlanCatalog::Exports,
                    PlatformPlanCatalog::AiAssistant,
                    PlatformPlanCatalog::WhatsappMessaging,
                    PlatformPlanCatalog::PrioritySupport,
                ],
                'is_active' => true,
                'sort_order' => 3,
            ],
        ];

        foreach ($plans as $plan) {
            PlatformPlan::updateOrCreate(
                ['slug' => $plan['slug']],
                $plan,
            );
        }

        PlatformPlan::query()
            ->whereIn('slug', ['starter', 'pro', 'enterprise'])
            ->update(['is_active' => false]);
    }
}
