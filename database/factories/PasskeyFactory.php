<?php

namespace Database\Factories;

use App\Models\Passkey;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Passkey>
 */
class PasskeyFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => fake()->randomElement(['MacBook Pro', 'iPhone', 'Windows PC', 'Android Phone']),
            'credential_id' => base64_encode(random_bytes(32)),
            'public_key' => base64_encode(random_bytes(77)),
            'aaguid' => fake()->uuid(),
            'sign_count' => fake()->numberBetween(0, 100),
            'attachment_type' => fake()->randomElement(['platform', 'cross-platform']),
            'transports' => ['internal'],
            'last_used_at' => fake()->optional()->dateTimeBetween('-30 days', 'now'),
        ];
    }
}
