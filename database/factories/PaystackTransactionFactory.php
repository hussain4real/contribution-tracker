<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Models\Family;
use App\Models\PaystackTransaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PaystackTransaction>
 */
class PaystackTransactionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<model-property<PaystackTransaction>, mixed>
     */
    public function definition(): array
    {
        return [
            'reference' => fake()->unique()->bothify('PSK-########'),
            'user_id' => User::factory(),
            'family_id' => Family::factory(),
            'type' => TransactionType::Contribution,
            'amount' => 1000,
            'gross_amount_kobo' => 100000,
            'estimated_fee_kobo' => 1500,
            'actual_fee_kobo' => 1500,
            'settled_amount_kobo' => 98500,
            'fee_policy' => 'family_absorbs',
            'status' => TransactionStatus::Allocated,
            'verified_at' => now(),
            'allocated_at' => now(),
        ];
    }
}
