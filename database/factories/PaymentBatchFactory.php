<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\PaymentMethod;
use App\Enums\PaymentSource;
use App\Models\Family;
use App\Models\PaymentBatch;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PaymentBatch>
 */
class PaymentBatchFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<model-property<PaymentBatch>, mixed>
     */
    public function definition(): array
    {
        return [
            'family_id' => Family::factory(),
            'family_membership_id' => null,
            'member_name' => fake()->name(),
            'total_amount' => fake()->numberBetween(100, 10000),
            'paid_at' => now(),
            'method' => PaymentMethod::Cash,
            'source' => PaymentSource::Manual,
            'reference' => null,
            'recorded_by' => User::factory(),
            'notes' => null,
            'receipt_number' => 1,
            'idempotency_key' => (string) fake()->uuid(),
        ];
    }
}
