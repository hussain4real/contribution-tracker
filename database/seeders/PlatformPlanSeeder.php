<?php

namespace Database\Seeders;

use App\Models\PlatformPlan;
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
                'slug' => 'free',
                'price' => 0,
                'max_members' => 5,
                'features' => ['basic_contributions', 'manual_payments', 'online_payments'],
                'sort_order' => 0,
            ],
            [
                'name' => 'Starter',
                'slug' => 'starter',
                'price' => 2000,
                'max_members' => 20,
                'features' => ['basic_contributions', 'manual_payments', 'online_payments', 'reports'],
                'sort_order' => 1,
            ],
            [
                'name' => 'Pro',
                'slug' => 'pro',
                'price' => 5000,
                'max_members' => 50,
                'features' => ['basic_contributions', 'manual_payments', 'online_payments', 'reports', 'exports'],
                'sort_order' => 2,
            ],
            [
                'name' => 'Enterprise',
                'slug' => 'enterprise',
                'price' => 10000,
                'max_members' => null,
                'features' => ['basic_contributions', 'manual_payments', 'online_payments', 'reports', 'exports', 'priority_support'],
                'sort_order' => 3,
            ],
        ];

        foreach ($plans as $plan) {
            PlatformPlan::updateOrCreate(
                ['slug' => $plan['slug']],
                $plan,
            );
        }
    }
}
