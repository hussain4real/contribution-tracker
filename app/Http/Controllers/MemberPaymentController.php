<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Http\Requests\InitiatePaymentRequest;
use App\Models\PaystackTransaction;
use App\Models\User;
use App\Services\PaymentAllocationService;
use App\Services\PaystackService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class MemberPaymentController extends Controller
{
    public function __construct(
        private PaystackService $paystack,
        private PaymentAllocationService $allocationService
    ) {}

    /**
     * Show the member's pending contributions for self-pay.
     */
    public function show(): Response
    {
        /** @var User $user */
        $user = auth()->user();

        $pendingContributions = $user->contributions()
            ->incomplete()
            ->oldestFirst()
            ->get()
            ->map(fn ($contribution) => [
                'id' => $contribution->id,
                'year' => $contribution->year,
                'month' => $contribution->month,
                'expected_amount' => $contribution->expected_amount,
                'total_paid' => $contribution->total_paid,
                'balance' => $contribution->balance,
                'status' => $contribution->status,
                'period_label' => $contribution->period_label,
            ]);

        $family = $user->family;

        return Inertia::render('Pay/Index', [
            'pending_contributions' => $pendingContributions,
            'category_amount' => $user->getMonthlyAmount() ?? 0,
            'formatted_amount' => $user->category?->formattedAmount() ?? '₦0',
            'has_paystack' => $family?->hasBankDetails() && config('services.paystack.public_key'),
            'paystack_public_key' => config('services.paystack.public_key'),
        ]);
    }

    /**
     * Initialize a Paystack transaction for contribution payment.
     */
    public function initiate(InitiatePaymentRequest $request): JsonResponse
    {
        $validated = $request->validated();

        /** @var User $user */
        $user = auth()->user();
        $family = $user->family;

        if (! $family?->hasBankDetails()) {
            return response()->json([
                'message' => 'Online payments are not set up for your family. Ask your admin to configure bank details.',
            ], 422);
        }

        // Calculate total from selected contributions
        $contributions = $user->contributions()
            ->whereIn('id', $validated['contribution_ids'])
            ->incomplete()
            ->get();

        if ($contributions->isEmpty()) {
            return response()->json([
                'message' => 'No unpaid contributions selected.',
            ], 422);
        }

        $totalAmount = $contributions->sum('balance');

        if ($totalAmount <= 0) {
            return response()->json([
                'message' => 'Selected contributions are already paid.',
            ], 422);
        }

        // Amount in kobo for Paystack (already stored in Naira, ×100 for kobo)
        $paystackAmount = $totalAmount * 100;

        $reference = 'TXN_'.Str::upper(Str::random(16));

        // Determine target month (oldest selected contribution)
        $oldest = $contributions->sortBy([['year', 'asc'], ['month', 'asc']])->first();

        // Store transaction locally
        $transaction = PaystackTransaction::create([
            'reference' => $reference,
            'user_id' => $user->id,
            'family_id' => $family->id,
            'type' => TransactionType::Contribution,
            'amount' => $totalAmount,
            'status' => TransactionStatus::Pending,
            'metadata' => [
                'contribution_ids' => $contributions->pluck('id')->toArray(),
                'target_year' => $oldest->year,
                'target_month' => $oldest->month,
            ],
        ]);

        try {
            $transactionData = [
                'email' => $user->email,
                'amount' => $paystackAmount,
                'reference' => $reference,
                'callback_url' => route('pay.callback'),
                'metadata' => [
                    'member_id' => $user->id,
                    'family_id' => $family->id,
                    'contribution_ids' => $contributions->pluck('id')->toArray(),
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
            }

            $response = $this->paystack->initializeTransaction($transactionData);

            return response()->json([
                'access_code' => $response['data']['access_code'],
                'authorization_url' => $response['data']['authorization_url'],
                'reference' => $reference,
            ]);
        } catch (\RuntimeException $e) {
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

        if (! $reference) {
            return redirect()->route('pay.index')->with('error', 'Invalid payment reference.');
        }

        $transaction = PaystackTransaction::where('reference', $reference)->first();

        if (! $transaction) {
            return redirect()->route('pay.index')->with('error', 'Transaction not found.');
        }

        // Verify with Paystack
        try {
            $response = $this->paystack->verifyTransaction($reference);
            $status = $response['data']['status'] ?? 'failed';

            if ($status === 'success' && $transaction->isPending()) {
                $transaction->update([
                    'status' => TransactionStatus::Success,
                    'paystack_response' => $response['data'],
                ]);

                // Allocate payment to contributions (webhook may not reach local dev)
                $member = auth()->user();
                $metadata = $transaction->metadata ?? [];
                $targetYear = $metadata['target_year'] ?? null;
                $targetMonth = $metadata['target_month'] ?? null;

                $this->allocationService->allocate(
                    member: $member,
                    amount: $transaction->amount,
                    paidAt: $response['data']['paid_at'] ?? now()->toDateString(),
                    recordedBy: $member,
                    notes: "Online payment via Paystack (Ref: {$transaction->reference})",
                    targetYear: $targetYear,
                    targetMonth: $targetMonth,
                );

                return redirect()->route('pay.index')->with('success', 'Payment successful! Your contributions have been updated.');
            }

            if ($transaction->isSuccessful()) {
                return redirect()->route('pay.index')->with('success', 'Payment already confirmed.');
            }

            return redirect()->route('pay.index')->with('error', 'Payment was not successful. Please try again.');
        } catch (\RuntimeException) {
            return redirect()->route('pay.index')->with('error', 'Could not verify payment. Your payment will be confirmed shortly.');
        }
    }
}
