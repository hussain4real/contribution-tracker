<?php

namespace Database\Factories;

use App\Models\FundAdjustment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FundAdjustment>
 */
class FundAdjustmentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'amount' => fake()->numberBetween(10000, 500000),
            'description' => fake()->sentence(),
            'recorded_at' => now(),
            'recorded_by' => User::factory()->superAdmin(),
        ];
    }

    /**
     * Create an adjustment with a specific amount.
     */
    public function withAmount(int $amount): static
    {
        return $this->state(fn (array $attributes) => [
            'amount' => $amount,
        ]);
    }

    /**
     * Create an adjustment recorded by a specific user.
     */
    public function recordedBy(User $user): static
    {
        return $this->state(fn (array $attributes) => [
            'recorded_by' => $user->id,
        ]);
    }
}
