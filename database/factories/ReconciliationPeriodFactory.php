<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ReconciliationPeriodStatus;
use App\Models\Family;
use App\Models\ReconciliationPeriod;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReconciliationPeriod>
 */
class ReconciliationPeriodFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<model-property<ReconciliationPeriod>, mixed>
     */
    public function definition(): array
    {
        return [
            'family_id' => Family::factory(),
            'starts_at' => now()->startOfMonth()->toDateString(),
            'ends_at' => now()->endOfMonth()->toDateString(),
            'status' => ReconciliationPeriodStatus::Open,
        ];
    }
}
