<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Family;
use App\Models\ProviderSettlementGroup;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProviderSettlementGroup>
 */
class ProviderSettlementGroupFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<model-property<ProviderSettlementGroup>, mixed>
     */
    public function definition(): array
    {
        return [
            'family_id' => Family::factory(),
            'provider' => 'paystack',
            'reference' => fake()->unique()->bothify('SET-#####'),
            'settled_at' => now()->toDateString(),
            'gross_amount' => 1000,
            'fee_amount' => 20,
            'net_amount' => 980,
            'bank_amount' => 980,
            'difference' => 0,
            'created_by' => null,
        ];
    }
}
