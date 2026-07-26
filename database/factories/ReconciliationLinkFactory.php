<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\BankTransaction;
use App\Models\Family;
use App\Models\PaymentBatch;
use App\Models\ReconciliationLink;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReconciliationLink>
 */
class ReconciliationLinkFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<model-property<ReconciliationLink>, mixed>
     */
    public function definition(): array
    {
        return [
            'family_id' => Family::factory(),
            'bank_transaction_id' => BankTransaction::factory(),
            'reconcilable_type' => PaymentBatch::MORPH_TYPE,
            'reconcilable_id' => PaymentBatch::factory(),
            'amount' => 100,
            'created_by' => null,
            'notes' => null,
        ];
    }
}
