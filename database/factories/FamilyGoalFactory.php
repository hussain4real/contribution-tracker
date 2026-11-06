<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\GoalMeasure;
use App\Models\Family;
use App\Models\FamilyGoal;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<FamilyGoal>
 */
class FamilyGoalFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<model-property<FamilyGoal>, mixed>
     */
    public function definition(): array
    {
        return [
            'family_id' => Family::factory(),
            'name' => 'Family education goal',
            'measure' => GoalMeasure::NetSavings,
            'target_amount' => 10000,
            'starts_on' => now()->startOfMonth()->toDateString(),
            'deadline' => now()->endOfMonth()->toDateString(),
            'idempotency_key' => (string) Str::uuid(),
        ];
    }
}
