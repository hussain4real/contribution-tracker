<?php

namespace Database\Factories;

use App\Models\WhatsAppMessage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WhatsAppMessage>
 */
class WhatsAppMessageFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'wa_message_id' => 'wamid.'.fake()->unique()->bothify('??##??##??##??##'),
            'direction' => 'outbound',
            'from' => '1038448572690931',
            'to' => fake()->numerify('234##########'),
            'type' => 'template',
            'body' => null,
            'template_name' => 'contribution_reminder',
            'payload' => ['messaging_product' => 'whatsapp'],
            'status' => 'sent',
            'error_code' => null,
            'error_message' => null,
            'family_id' => null,
            'user_id' => null,
            'wa_timestamp' => now(),
        ];
    }

    public function inbound(): static
    {
        return $this->state(fn (array $attributes) => [
            'direction' => 'inbound',
            'from' => fake()->numerify('234##########'),
            'to' => '1038448572690931',
            'type' => 'text',
            'body' => fake()->sentence(),
            'template_name' => null,
            'status' => 'received',
        ]);
    }

    public function delivered(): static
    {
        return $this->state(fn (array $attributes) => ['status' => 'delivered']);
    }

    public function read(): static
    {
        return $this->state(fn (array $attributes) => ['status' => 'read']);
    }

    public function failed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'failed',
            'error_code' => '131000',
            'error_message' => 'Generic delivery error',
        ]);
    }
}
