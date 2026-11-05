<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\PaymentBatch;
use App\Models\PaymentReceiptDelivery;
use Illuminate\Database\Seeder;

class PaymentReceiptDeliverySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        PaymentBatch::query()->chunkById(200, function ($batches): void {
            foreach ($batches as $batch) {
                foreach (['mail', 'whatsapp'] as $channel) {
                    PaymentReceiptDelivery::query()->firstOrCreate([
                        'payment_batch_id' => $batch->id,
                        'channel' => $channel,
                    ], ['status' => 'unknown']);
                }
            }
        });
    }
}
