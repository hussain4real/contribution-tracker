<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Http\Requests\InitiatePaymentRequest;
use App\Models\Contribution;
use App\Models\Expense;
use App\Models\Family;
use App\Models\PaystackTransaction;
use App\Models\User;
use App\Services\PaymentAllocationService;
use App\Services\PaystackFeeCalculator;
use App\Services\PaystackService;
use App\Support\CurrencyFormatter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

class MemberPaymentController extends Controller
{
    public function __construct(
        private PaystackService $paystack,
        private PaymentAllocationService $allocationService,
        private PaystackFeeCalculator $feeCalculator
    ) {}

    /**
     * Show the member's pending contributions for self-pay.
     */
    public function show(): Response
    {
        $user = $this->authUser();
        $family = app(Family::class);

        $pendingContributions = $user->contributions()
            ->where('family_id', $family->id)
            ->incomplete()
            ->oldestFirst()
            ->get()
            ->map(fn (Contribution $contribution): array => [
                'id' => $contribution->id,
                'year' => $contribution->year,
                'month' => $contribution->month,
                'expected_amount' => $contribution->expected_amount,
                'total_paid' => $contribution->total_paid,
                'balance' => $contribution->balance,
                'status' => $contribution->status,
                'period_label' => $contribution->period_label,
            ]);

        $paystackPublicKey = $this->stringConfig('services.paystack.public_key');
        $bankSetupStatus = $this->bankSetupStatus($family, $paystackPublicKey);

        return Inertia::render('Pay/Index', [
            'pending_contributions' => $pendingContributions,
            'category_amount' => $user->getMonthlyAmount() ?? 0,
            'formatted_amount' => CurrencyFormatter::format($user->getMonthlyAmount() ?? 0, $family->currency),
            'has_paystack' => $bankSetupStatus === 'ready',
            'bank_setup_status' => $bankSetupStatus,
            'paystack_public_key' => $paystackPublicKey,
            'paystack_fee' => $this->feeCalculator->publicConfig(),
        ]);
    }

    /**
     * Initialize a Paystack transaction for contribution payment.
     */
    public function initiate(InitiatePaymentRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $user = $this->authUser();
        $family = app(Family::class);

        if (! $family->hasBankDetails()) {
            return response()->json([
                'message' => 'Online payments are not set up for your family. Ask your admin to configure bank details.',
            ], 422);
        }

        $contributionIds = $this->integerList($validated['contribution_ids'] ?? []);

        // Calculate total from selected contributions
        $contributions = $user->contributions()
            ->where('family_id', $family->id)
            ->whereIn('id', $contributionIds)
            ->incomplete()
            ->get();

        if ($contributions->isEmpty()) {
            return response()->json([
                'message' => 'No unpaid contributions selected.',
            ], 422);
        }

        $totalAmount = (int) $contributions->sum(fn (Contribution $contribution): int => $contribution->balance);

        if ($totalAmount <= 0) {
            return response()->json([
                'message' => 'Selected contributions are already paid.',
            ], 422);
        }

        $feeEstimate = $this->feeCalculator->estimateForContributionAmount($totalAmount);

        $reference = 'TXN_'.Str::upper(Str::random(16));

        // Determine target month (oldest selected contribution)
        $oldest = $contributions->sortBy([['year', 'asc'], ['month', 'asc']])->firstOrFail();

        // Store transaction locally
        $transaction = PaystackTransaction::create([
            'reference' => $reference,
            'user_id' => $user->id,
            'family_id' => $family->id,
            'type' => TransactionType::Contribution,
            'amount' => $totalAmount,
            'gross_amount_kobo' => $feeEstimate['gross_amount_kobo'],
            'estimated_fee_kobo' => $feeEstimate['estimated_fee_kobo'],
            'settled_amount_kobo' => $feeEstimate['settled_amount_kobo'],
            'fee_policy' => $feeEstimate['fee_policy'],
            'status' => TransactionStatus::Pending,
            'metadata' => [
                'contribution_ids' => $contributions->pluck('id')->toArray(),
                'target_year' => $oldest->year,
                'target_month' => $oldest->month,
                'contribution_amount_kobo' => $feeEstimate['contribution_amount_kobo'],
                'gross_amount_kobo' => $feeEstimate['gross_amount_kobo'],
                'estimated_fee_kobo' => $feeEstimate['estimated_fee_kobo'],
                'fee_policy' => $feeEstimate['fee_policy'],
            ],
        ]);

        try {
            $transactionData = [
                'email' => $user->email,
                'amount' => $feeEstimate['gross_amount_kobo'],
                'reference' => $reference,
                'callback_url' => route('pay.callback'),
                'metadata' => [
                    'member_id' => $user->id,
                    'family_id' => $family->id,
                    'contribution_ids' => $contributions->pluck('id')->toArray(),
                    'contribution_amount_kobo' => $feeEstimate['contribution_amount_kobo'],
                    'gross_amount_kobo' => $feeEstimate['gross_amount_kobo'],
                    'estimated_fee_kobo' => $feeEstimate['estimated_fee_kobo'],
                    'fee_policy' => $feeEstimate['fee_policy'],
                    'custom_fields' => [
                        [
                            'display_name' => 'Member',
                            'variable_name' => 'member_name',
                            'value' => $user->name,
                        ],
                        [
                            'display_name' => 'Family',
                            'variable_name' => 'family_name',
                            'value' => $family->name,
                        ],
                    ],
                ],
            ];

            if ($family->paystack_subaccount_code) {
                $transactionData['subaccount'] = $family->paystack_subaccount_code;
                $transactionData['bearer'] = 'subaccount';
            }

            $response = $this->paystack->initializeTransaction($transactionData);
            $data = $this->responseData($response);

            return response()->json([
                'access_code' => $this->requiredString($data, 'access_code'),
                'authorization_url' => $this->requiredString($data, 'authorization_url'),
                'reference' => $reference,
                'contribution_amount_kobo' => $feeEstimate['contribution_amount_kobo'],
                'gross_amount_kobo' => $feeEstimate['gross_amount_kobo'],
                'estimated_fee_kobo' => $feeEstimate['estimated_fee_kobo'],
            ]);
        } catch (RuntimeException $e) {
            $transaction->update(['status' => TransactionStatus::Failed]);

            return response()->json([
                'message' => 'Failed to initialize payment. Please try again.',
            ], 500);
        }
    }

    /**
     * Handle Paystack callback redirect.
     */
    public function callback(Request $request): RedirectResponse
    {
        $reference = $request->query('reference');

        if (! is_string($reference) || $reference === '') {
            return redirect()->route('pay.index')->with('error', 'Invalid payment reference.');
        }

        $transaction = PaystackTransaction::where('reference', $reference)->first();

        if (! $transaction) {
            return redirect()->route('pay.index')->with('error', 'Transaction not found.');
        }

        // Verify with Paystack
        try {
            $response = $this->paystack->verifyTransaction($reference);
            $data = $this->responseData($response);
            $status = $this->stringValue($data['status'] ?? 'failed');

            if ($status === 'success') {
                $paystackAmountKobo = $this->nullableInt($data['amount'] ?? null) ?? 0;
                $expectedAmountKobo = $transaction->expectedGrossAmountKobo();

                if ($paystackAmountKobo !== $expectedAmountKobo) {
                    Log::warning('Paystack callback: amount mismatch', [
                        'reference' => $reference,
                        'expected_kobo' => $expectedAmountKobo,
                        'received_kobo' => $paystackAmountKobo,
                    ]);

                    $transaction->update([
                        'status' => TransactionStatus::Failed,
                        'paystack_response' => $data,
                    ]);

                    return redirect()->route('pay.index')->with('error', 'Payment amount could not be verified. Please contact support if you were charged.');
                }

                // Atomic update to prevent race condition with webhook
                $attributes = $this->paystackSettlementAttributes($transaction, $data, forQuery: true);
                $attributes['status'] = TransactionStatus::Success;

                $updated = PaystackTransaction::where('reference', $reference)
                    ->where('status', TransactionStatus::Pending)
                    ->update($attributes);

                if ($updated > 0) {
                    $transaction->refresh();

                    // Use the transaction's user_id (not session user) to handle shared devices
                    $member = User::query()->whereKey($transaction->user_id)->first();

                    if ($member) {
                        $metadata = $transaction->metadata ?? [];
                        $targetYear = $this->nullableInt($metadata['target_year'] ?? null);
                        $targetMonth = $this->nullableInt($metadata['target_month'] ?? null);
                        $paidAt = $this->stringValue($data['paid_at'] ?? now()->toDateString());

                        $this->allocationService->allocate(
                            member: $member,
                            amount: $transaction->amount,
                            paidAt: $paidAt !== '' ? $paidAt : now()->toDateString(),
                            recordedBy: $member,
                            notes: "Online payment via Paystack (Ref: {$transaction->reference})",
                            targetYear: $targetYear,
                            targetMonth: $targetMonth,
                        );

                        $this->recordSettlementShortfallExpense($transaction, $member, $data);
                    }
                }

                return redirect()->route('pay.index')->with('success', 'Payment successful! Your contributions have been updated.');
            }

            if ($transaction->isSuccessful()) {
                return redirect()->route('pay.index')->with('success', 'Payment already confirmed.');
            }

            return redirect()->route('pay.index')->with('error', 'Payment was not successful. Please try again.');
        } catch (RuntimeException) {
            return redirect()->route('pay.index')->with('error', 'Could not verify payment. Your payment will be confirmed shortly.');
        }
    }

    private function stringConfig(string $key): string
    {
        $value = config($key);

        return is_string($value) ? $value : '';
    }

    private function bankSetupStatus(Family $family, string $paystackPublicKey): string
    {
        if (! $family->hasBankDetails()) {
            return filled($family->bank_name) || filled($family->account_name) || filled($family->account_number)
                ? 'incomplete_bank_details'
                : 'missing_bank_details';
        }

        if ($paystackPublicKey === '') {
            return 'missing_paystack_key';
        }

        return 'ready';
    }

    /**
     * @return list<int>
     */
    private function integerList(mixed $value): array
    {
        $items = [];

        foreach (is_array($value) ? $value : [] as $item) {
            if (is_numeric($item)) {
                $items[] = (int) $item;
            }
        }

        return $items;
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

    private function stringValue(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
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

        $spentAt = $this->stringValue($data['paid_at'] ?? now()->toDateString());

        Expense::create([
            'family_id' => $transaction->family_id,
            'amount' => intdiv($shortfallKobo + 99, 100),
            'description' => "Paystack processing fee shortfall for transaction {$transaction->reference}",
            'spent_at' => $spentAt !== '' ? $spentAt : now()->toDateString(),
            'recorded_by' => $member->id,
        ]);
    }
}
