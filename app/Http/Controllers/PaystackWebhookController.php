<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Models\Expense;
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
        $data = $this->stringKeyedArray($request->input('data'));

        Log::info('Paystack webhook received', ['event' => $event]);

        return match ($event) {
            'charge.success' => $this->handleChargeSuccess($data ?? []),
            'subscription.create' => $this->handleSubscriptionCreate($data ?? []),
            'subscription.not_renew' => $this->handleSubscriptionNotRenew($data ?? []),
            'invoice.payment_failed' => $this->handleInvoicePaymentFailed($data ?? []),
            default => response()->json(['message' => 'Event ignored']),
        };
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function handleChargeSuccess(array $data): JsonResponse
    {
        $reference = $data['reference'] ?? null;

        if (! is_string($reference) || $reference === '') {
            return response()->json(['message' => 'No reference'], 400);
        }

        $transaction = PaystackTransaction::where('reference', $reference)->first();

        if (! $transaction) {
            Log::info('Paystack webhook: unknown transaction reference', ['reference' => $reference]);

            return response()->json(['message' => 'Unknown reference']);
        }

        // Prevent double processing
        if ($transaction->status === TransactionStatus::Success) {
            return response()->json(['message' => 'Already processed']);
        }

        // Verify amount matches the gross Paystack charge in kobo.
        $paystackAmountKobo = is_numeric($data['amount'] ?? null) ? (int) $data['amount'] : 0;
        $expectedAmountKobo = $transaction->expectedGrossAmountKobo();
        if ($paystackAmountKobo !== $expectedAmountKobo) {
            Log::warning('Paystack webhook: amount mismatch', [
                'reference' => $reference,
                'expected_kobo' => $expectedAmountKobo,
                'received_kobo' => $paystackAmountKobo,
            ]);

            $attributes = $this->paystackSettlementAttributes($transaction, $data);
            $attributes['status'] = TransactionStatus::Failed;

            $transaction->update($attributes);

            return response()->json(['message' => 'Amount mismatch'], 400);
        }

        // Atomic update to prevent race condition with callback
        $attributes = $this->paystackSettlementAttributes($transaction, $data, forQuery: true);
        $attributes['status'] = TransactionStatus::Success;

        $updated = PaystackTransaction::where('reference', $reference)
            ->where('status', TransactionStatus::Pending)
            ->update($attributes);

        if ($updated === 0) {
            return response()->json(['message' => 'Already processed']);
        }

        $transaction->refresh();

        if ($transaction->type === TransactionType::Contribution) {
            $this->allocateContributionPayment($transaction, $data);
        }

        return response()->json(['message' => 'Success']);
    }

    /**
     * @param  array<string, mixed>  $data
     */
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
        $paidAt = $data['paid_at'] ?? now()->toDateString();

        $this->allocationService->allocate(
            member: $member,
            amount: $transaction->amount,
            paidAt: is_scalar($paidAt) ? (string) $paidAt : now()->toDateString(),
            recordedBy: $member,
            notes: "Paystack payment (ref: {$transaction->reference})",
            targetYear: is_numeric($targetYear) ? (int) $targetYear : null,
            targetMonth: is_numeric($targetMonth) ? (int) $targetMonth : null,
        );

        $this->recordSettlementShortfallExpense($transaction, $member, $data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function handleSubscriptionCreate(array $data): JsonResponse
    {
        $subscriptionCode = $data['subscription_code'] ?? null;
        $customer = $this->stringKeyedArray($data['customer'] ?? null);
        $customerCode = $customer['customer_code'] ?? null;

        if (! is_string($subscriptionCode) || ! is_string($customerCode)) {
            return response()->json(['message' => 'Missing subscription data'], 400);
        }

        $family = Family::where('paystack_customer_code', $customerCode)->first();

        if (! $family) {
            Log::warning('Paystack webhook: family not found for customer', ['customer_code' => $customerCode]);

            return response()->json(['message' => 'Family not found']);
        }

        $family->update([
            'paystack_subscription_code' => $subscriptionCode,
            'paystack_subscription_email_token' => $data['email_token'] ?? null,
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

        if (! is_string($subscriptionCode)) {
            return response()->json(['message' => 'Missing subscription data'], 400);
        }

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
        $subscription = $this->stringKeyedArray($data['subscription'] ?? null);
        $subscriptionCode = $subscription['subscription_code'] ?? null;

        if (! is_string($subscriptionCode)) {
            return response()->json(['message' => 'Missing subscription data'], 400);
        }

        $family = Family::where('paystack_subscription_code', $subscriptionCode)->first();

        if ($family) {
            $family->update([
                'subscription_status' => 'past_due',
            ]);
        }

        return response()->json(['message' => 'Payment failure recorded']);
    }

    /**
     * @return array<string, mixed>|null
     */
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

    private function nullableInt(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<model-property<PaystackTransaction>, mixed>
     */
    private function paystackSettlementAttributes(PaystackTransaction $transaction, array $data, bool $forQuery = false): array
    {
        $grossAmountKobo = $this->nullableInt($data['amount'] ?? null) ?? $transaction->expectedGrossAmountKobo();
        $actualFeeKobo = $this->nullableInt($data['fees'] ?? null);
        $effectiveFeeKobo = $actualFeeKobo
            ?? $transaction->estimated_fee_kobo
            ?? max(0, $grossAmountKobo - $transaction->contributionAmountKobo());

        $attributes = [
            'paystack_response' => $forQuery ? json_encode($data) : $data,
            'gross_amount_kobo' => $grossAmountKobo,
            'settled_amount_kobo' => max(0, $grossAmountKobo - $effectiveFeeKobo),
        ];

        if ($actualFeeKobo !== null) {
            $attributes['actual_fee_kobo'] = $actualFeeKobo;
        }

        return $attributes;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function recordSettlementShortfallExpense(PaystackTransaction $transaction, User $member, array $data): void
    {
        $settledAmountKobo = $transaction->settled_amount_kobo ?? $transaction->expectedGrossAmountKobo();
        $shortfallKobo = $transaction->contributionAmountKobo() - $settledAmountKobo;

        if ($shortfallKobo <= 0) {
            return;
        }

        $paidAt = $data['paid_at'] ?? now()->toDateString();

        Expense::create([
            'family_id' => $transaction->family_id,
            'amount' => intdiv($shortfallKobo + 99, 100),
            'description' => "Paystack processing fee shortfall for transaction {$transaction->reference}",
            'spent_at' => is_scalar($paidAt) ? (string) $paidAt : now()->toDateString(),
            'recorded_by' => $member->id,
        ]);
    }
}
