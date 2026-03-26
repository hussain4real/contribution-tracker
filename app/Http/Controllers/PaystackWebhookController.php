<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Family;
use App\Models\PaystackTransaction;
use App\Models\User;
use App\Services\PaymentAllocationService;
use App\Services\PaystackService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PaystackWebhookController extends Controller
{
    public function __construct(
        private PaystackService $paystack,
        private PaymentAllocationService $allocationService
    ) {}

    public function handle(Request $request): JsonResponse
    {
        $payload = $request->getContent();
        $signature = $request->header('X-Paystack-Signature', '');

        if (! $this->paystack->verifyWebhookSignature($payload, $signature)) {
            Log::warning('Paystack webhook: invalid signature');

            return response()->json(['message' => 'Invalid signature'], 403);
        }

        $event = $request->input('event');
        $data = $request->input('data');

        Log::info('Paystack webhook received', ['event' => $event]);

        return match ($event) {
            'charge.success' => $this->handleChargeSuccess($data),
            'subscription.create' => $this->handleSubscriptionCreate($data),
            'subscription.not_renew' => $this->handleSubscriptionNotRenew($data),
            'invoice.payment_failed' => $this->handleInvoicePaymentFailed($data),
            default => response()->json(['message' => 'Event ignored']),
        };
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function handleChargeSuccess(array $data): JsonResponse
    {
        $reference = $data['reference'] ?? null;

        if (! $reference) {
            return response()->json(['message' => 'No reference'], 400);
        }

        $transaction = PaystackTransaction::where('reference', $reference)->first();

        if (! $transaction) {
            Log::info('Paystack webhook: unknown transaction reference', ['reference' => $reference]);

            return response()->json(['message' => 'Unknown reference']);
        }

        // Prevent double processing
        if ($transaction->status === 'success') {
            return response()->json(['message' => 'Already processed']);
        }

        // Verify amount matches (Paystack sends amount in kobo, we store in Naira)
        $paystackAmountKobo = $data['amount'] ?? 0;
        $expectedAmountKobo = $transaction->amount * 100;
        if ($paystackAmountKobo !== $expectedAmountKobo) {
            Log::warning('Paystack webhook: amount mismatch', [
                'reference' => $reference,
                'expected_kobo' => $expectedAmountKobo,
                'received_kobo' => $paystackAmountKobo,
            ]);

            $transaction->update([
                'status' => 'failed',
                'paystack_response' => $data,
            ]);

            return response()->json(['message' => 'Amount mismatch'], 400);
        }

        if ($transaction->type === 'contribution') {
            $this->allocateContributionPayment($transaction, $data);
        }

        $transaction->update([
            'status' => 'success',
            'paystack_response' => $data,
        ]);

        return response()->json(['message' => 'Success']);
    }

    private function allocateContributionPayment(PaystackTransaction $transaction, array $data): void
    {
        $member = User::find($transaction->user_id);

        if (! $member) {
            Log::error('Paystack webhook: member not found', ['user_id' => $transaction->user_id]);

            return;
        }

        $metadata = $transaction->metadata ?? [];
        $targetYear = $metadata['target_year'] ?? null;
        $targetMonth = $metadata['target_month'] ?? null;

        $this->allocationService->allocate(
            member: $member,
            amount: $transaction->amount,
            paidAt: $data['paid_at'] ?? now()->toDateString(),
            recordedBy: $member,
            notes: "Paystack payment (ref: {$transaction->reference})",
            targetYear: $targetYear ? (int) $targetYear : null,
            targetMonth: $targetMonth ? (int) $targetMonth : null,
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function handleSubscriptionCreate(array $data): JsonResponse
    {
        $subscriptionCode = $data['subscription_code'] ?? null;
        $customerCode = $data['customer']['customer_code'] ?? null;

        if (! $subscriptionCode || ! $customerCode) {
            return response()->json(['message' => 'Missing subscription data'], 400);
        }

        $family = Family::where('paystack_customer_code', $customerCode)->first();

        if (! $family) {
            Log::warning('Paystack webhook: family not found for customer', ['customer_code' => $customerCode]);

            return response()->json(['message' => 'Family not found']);
        }

        $family->update([
            'paystack_subscription_code' => $subscriptionCode,
            'subscription_status' => 'active',
            'current_period_end' => $data['next_payment_date'] ?? null,
        ]);

        return response()->json(['message' => 'Subscription activated']);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function handleSubscriptionNotRenew(array $data): JsonResponse
    {
        $subscriptionCode = $data['subscription_code'] ?? null;

        $family = Family::where('paystack_subscription_code', $subscriptionCode)->first();

        if ($family) {
            $family->update([
                'subscription_status' => 'cancelled',
            ]);
        }

        return response()->json(['message' => 'Subscription cancelled']);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function handleInvoicePaymentFailed(array $data): JsonResponse
    {
        $subscriptionCode = $data['subscription']['subscription_code'] ?? null;

        $family = Family::where('paystack_subscription_code', $subscriptionCode)->first();

        if ($family) {
            $family->update([
                'subscription_status' => 'past_due',
            ]);
        }

        return response()->json(['message' => 'Payment failure recorded']);
    }
}
