<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\PaymentBatch;
use App\Models\PaystackTransaction;
use App\Models\ProviderSettlementGroup;
use App\Models\ProviderSettlementItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProviderSettlementItem>
 */
class ProviderSettlementItemFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<model-property<ProviderSettlementItem>, mixed>
     */
    public function definition(): array
    {
        return [
            'provider_settlement_group_id' => ProviderSettlementGroup::factory(),
            'paystack_transaction_id' => PaystackTransaction::factory(),
            'payment_batch_id' => PaymentBatch::factory(),
            'gross_amount' => 1000,
            'fee_amount' => 20,
            'net_amount' => 980,
        ];
    }
}
