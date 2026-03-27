<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Family;
use App\Services\PaystackService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class SyncPaystackSubaccount implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 10;

    public function __construct(
        public Family $family
    ) {}

    public function handle(PaystackService $paystack): void
    {
        $family = $this->family;

        if ($family->paystack_subaccount_code) {
            $paystack->updateSubaccount($family->paystack_subaccount_code, [
                'business_name' => $family->name,
                'bank_code' => $family->bank_code,
                'account_number' => $family->account_number,
                'percentage_charge' => 0,
            ]);
        } else {
            $response = $paystack->createSubaccount([
                'business_name' => $family->name,
                'bank_code' => $family->bank_code,
                'account_number' => $family->account_number,
                'percentage_charge' => 0,
            ]);

            $family->update([
                'paystack_subaccount_code' => $response['data']['subaccount_code'],
            ]);
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('Failed to sync Paystack subaccount', [
            'family_id' => $this->family->id,
            'error' => $exception->getMessage(),
        ]);
    }
}
