<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Family;
use App\Models\PaystackTransaction;
use Illuminate\Support\Facades\Log;

class PaystackWebhookProcessor
{
    public function __construct(
        private readonly PaystackContributionSettlementService $settlementService,
    ) {}

    /** @param array<string, mixed> $payload */
    public function process(array $payload): void
    {
        $event = $payload['event'] ?? null;
        $data = $this->stringKeyedArray($payload['data'] ?? null);

        if (! is_string($event) || $data === null) {
            Log::warning('Paystack webhook job received an invalid payload.');

            return;
        }

        match ($event) {
            'charge.success' => $this->processSuccessfulCharge($data),
            'subscription.create' => $this->processSubscriptionCreated($data),
            'subscription.not_renew' => $this->processSubscriptionNotRenewing($data),
            'invoice.payment_failed' => $this->processSubscriptionPaymentFailure($data),
            default => null,
        };
    }

    /** @param array<string, mixed> $data */
    private function processSuccessfulCharge(array $data): void
    {
        $reference = $data['reference'] ?? null;

        if (! is_string($reference) || $reference === '') {
            return;
        }

        if (! PaystackTransaction::query()->where('reference', $reference)->exists()) {
            Log::info('Paystack webhook ignored an unknown transaction reference.', ['reference' => $reference]);

            return;
        }

        $this->settlementService->settle($reference, $data);
    }

    /** @param array<string, mixed> $data */
    private function processSubscriptionCreated(array $data): void
    {
        $subscriptionCode = $data['subscription_code'] ?? null;
        $customer = $this->stringKeyedArray($data['customer'] ?? null);
        $customerCode = $customer['customer_code'] ?? null;

        if (! is_string($subscriptionCode) || ! is_string($customerCode)) {
            return;
        }

        Family::query()->where('paystack_customer_code', $customerCode)->update([
            'paystack_subscription_code' => $subscriptionCode,
            'paystack_subscription_email_token' => $data['email_token'] ?? null,
            'subscription_status' => 'active',
            'current_period_end' => $data['next_payment_date'] ?? null,
        ]);
    }

    /** @param array<string, mixed> $data */
    private function processSubscriptionNotRenewing(array $data): void
    {
        $subscriptionCode = $data['subscription_code'] ?? null;

        if (is_string($subscriptionCode)) {
            Family::query()->where('paystack_subscription_code', $subscriptionCode)->update([
                'subscription_status' => 'cancelled',
            ]);
        }
    }

    /** @param array<string, mixed> $data */
    private function processSubscriptionPaymentFailure(array $data): void
    {
        $subscription = $this->stringKeyedArray($data['subscription'] ?? null);
        $subscriptionCode = $subscription['subscription_code'] ?? null;

        if (is_string($subscriptionCode)) {
            Family::query()->where('paystack_subscription_code', $subscriptionCode)->update([
                'subscription_status' => 'past_due',
            ]);
        }
    }

    /** @return array<string, mixed>|null */
    private function stringKeyedArray(mixed $value): ?array
    {
        if (! is_array($value)) {
            return null;
        }

        $items = [];

        foreach ($value as $key => $item) {
            if (is_string($key)) {
                $items[$key] = $item;
            }
        }

        return $items;
    }
}
