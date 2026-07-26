<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\InvitationDeliveryMethod;
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
     * @return array<model-property<FamilyInvitation>, mixed>
     */
    public function definition(): array
    {
        return [
            'family_id' => Family::factory(),
            'email' => fake()->unique()->safeEmail(),
            'delivery_method' => InvitationDeliveryMethod::Email,
            'whatsapp_phone' => null,
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

    /**
     * Send the invitation over WhatsApp.
     */
    public function viaWhatsApp(?string $phone = null): static
    {
        return $this->state(fn (array $attributes) => [
            'delivery_method' => InvitationDeliveryMethod::WhatsApp,
            'email' => null,
            'whatsapp_phone' => $phone ?? fake()->e164PhoneNumber(),
        ]);
    }
}
