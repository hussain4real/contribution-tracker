<?php

namespace Database\Factories;

use App\Models\Expense;
use App\Models\Family;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Expense>
 */
class ExpenseFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'family_id' => Family::factory(),
            'amount' => fake()->numberBetween(1000, 50000),
            'description' => fake()->sentence(),
            'spent_at' => now(),
            'recorded_by' => User::factory()->admin(),
        ];
    }

    /**
     * Create an expense with a specific amount.
     */
    public function withAmount(int $amount): static
    {
        return $this->state(fn (array $attributes) => [
            'amount' => $amount,
        ]);
    }

    /**
     * Create an expense recorded by a specific user.
     */
    public function recordedBy(User $user): static
    {
        return $this->state(fn (array $attributes) => [
            'recorded_by' => $user->id,
            'family_id' => $user->family_id,
        ]);
    }

    /**
     * Create an expense spent on a specific date.
     */
    public function spentOn(string|\DateTimeInterface $date): static
    {
        return $this->state(fn (array $attributes) => [
            'spent_at' => $date,
        ]);
    }
}
