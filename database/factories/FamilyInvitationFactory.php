<?php

namespace Database\Factories;

use App\Enums\Role;
use App\Models\Family;
use App\Models\FamilyInvitation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<FamilyInvitation>
 */
class FamilyInvitationFactory extends Factory
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
            'email' => fake()->unique()->safeEmail(),
            'role' => Role::Member,
            'token' => Str::random(64),
            'invited_by' => User::factory(),
            'expires_at' => now()->addDays(7),
        ];
    }

    /**
     * Mark the invitation as accepted.
     */
    public function accepted(): static
    {
        return $this->state(fn (array $attributes) => [
            'accepted_at' => now(),
        ]);
    }

    /**
     * Mark the invitation as expired.
     */
    public function expired(): static
    {
        return $this->state(fn (array $attributes) => [
            'expires_at' => now()->subDay(),
        ]);
    }
}
