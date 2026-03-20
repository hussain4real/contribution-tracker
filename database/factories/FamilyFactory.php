<?php

namespace Database\Factories;

use App\Models\Family;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Family>
 */
class FamilyFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->lastName().' Family';

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::random(5),
            'currency' => '₦',
            'due_day' => 28,
        ];
    }

    /**
     * Set a specific currency.
     */
    public function withCurrency(string $currency): static
    {
        return $this->state(fn (array $attributes) => [
            'currency' => $currency,
        ]);
    }

    /**
     * Set a specific due day.
     */
    public function withDueDay(int $dueDay): static
    {
        return $this->state(fn (array $attributes) => [
            'due_day' => $dueDay,
        ]);
    }

    /**
     * Mark the family as suspended.
     */
    public function suspended(): static
    {
        return $this->state(fn (array $attributes) => [
            'suspended_at' => now(),
        ]);
    }
}
