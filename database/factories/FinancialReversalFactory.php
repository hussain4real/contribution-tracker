<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Expense;
use App\Models\Family;
use App\Models\FinancialReversal;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FinancialReversal>
 */
class FinancialReversalFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<model-property<FinancialReversal>, mixed>
     */
    public function definition(): array
    {
        return [
            'family_id' => Family::factory(),
            'reversible_type' => Expense::MORPH_TYPE,
            'reversible_id' => Expense::factory(),
            'replacement_type' => null,
            'replacement_id' => null,
            'reason' => fake()->sentence(),
            'reversed_by' => User::factory(),
            'request_id' => (string) fake()->uuid(),
        ];
    }
}
