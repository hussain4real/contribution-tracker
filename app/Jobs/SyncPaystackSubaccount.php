<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Family;
use App\Services\PaystackService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

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
        $bankCode = $family->bank_code;
        $accountNumber = $family->account_number;

        if ($bankCode === null || $accountNumber === null) {
            Log::warning('Skipping Paystack subaccount sync because bank details are incomplete', [
                'family_id' => $family->id,
            ]);

            return;
        }

        if ($family->paystack_subaccount_code) {
            $paystack->updateSubaccount($family->paystack_subaccount_code, [
                'business_name' => $family->name,
                'bank_code' => $bankCode,
                'account_number' => $accountNumber,
                'percentage_charge' => 0.0,
            ]);
        } else {
            $response = $paystack->createSubaccount([
                'business_name' => $family->name,
                'bank_code' => $bankCode,
                'account_number' => $accountNumber,
                'percentage_charge' => 0.0,
            ]);

            $subaccountCode = $this->requiredString($this->responseData($response), 'subaccount_code');

            $family->update([
                'paystack_subaccount_code' => $subaccountCode,
            ]);
        }
    }

    public function failed(Throwable $exception): void
    {
        Log::error('Failed to sync Paystack subaccount', [
            'family_id' => $this->family->id,
            'error' => $exception->getMessage(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $response
     * @return array<string, mixed>
     */
    private function responseData(array $response): array
    {
        $data = $response['data'] ?? null;

        if (! is_array($data)) {
            throw new RuntimeException('Paystack response did not include a data payload.');
        }

        $items = [];

        foreach ($data as $key => $value) {
            if (is_string($key)) {
                $items[$key] = $value;
            }
        }

        return $items;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function requiredString(array $data, string $key): string
    {
        $value = $data[$key] ?? null;

        if (! is_string($value) || $value === '') {
            throw new RuntimeException("Paystack response did not include {$key}.");
        }

        return $value;
    }
}
