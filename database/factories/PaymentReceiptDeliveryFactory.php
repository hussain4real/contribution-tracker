<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\PaymentBatch;
use App\Models\PaymentReceiptDelivery;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PaymentReceiptDelivery>
 */
class PaymentReceiptDeliveryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<model-property<PaymentReceiptDelivery>, mixed>
     */
    public function definition(): array
    {
        return [
            'payment_batch_id' => PaymentBatch::factory(),
            'channel' => 'mail',
            'status' => 'queued',
        ];
    }
}
