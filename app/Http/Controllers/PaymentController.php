<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\MemberCategory;
use App\Http\Requests\StorePaymentRequest;
use App\Models\Payment;
use App\Models\User;
use App\Services\PaymentAllocationService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class PaymentController extends Controller
{
    public function __construct(
        private PaymentAllocationService $allocationService
    ) {}

    /**
     * Display member selection for recording a payment.
     */
    public function index(): Response
    {
        $this->authorize('create', Payment::class);

        $currentUser = $this->authUser();

        $members = User::query()
            ->where('family_id', $currentUser->family_id)
            ->whereNull('archived_at')
            ->whereNotNull('category')
            ->orderBy('name')
            ->get()
            ->map(fn (User $member): array => [
                'id' => $member->id,
                'name' => $member->name,
                'email' => $member->email,
                'category' => $member->category?->value,
                'category_label' => $member->category?->label(),
                'monthly_amount' => $member->getMonthlyAmount() ?? 0,
            ]);

        return Inertia::render('Payments/Index', [
            'members' => $members,
        ]);
    }

    /**
     * Show the form for recording a new payment for a member.
     */
    public function create(User $member): Response
    {
        $this->authorize('create', Payment::class);

        // Get member's pending (incomplete) contributions
        $pendingContributions = $member->contributions()
            ->incomplete()
            ->oldestFirst()
            ->get()
            ->map(fn ($contribution): array => [
                'id' => $contribution->id,
                'year' => $contribution->year,
                'month' => $contribution->month,
                'expected_amount' => $contribution->expected_amount,
                'total_paid' => $contribution->total_paid,
                'balance' => $contribution->balance,
                'status' => $contribution->status,
                'period_label' => $contribution->period_label,
            ]);

        return Inertia::render('Payments/Create', [
            'member' => $member->only(['id', 'name', 'email', 'category']),
            'pending_contributions' => $pendingContributions,
            'category_amount' => $member->category?->monthlyAmount(),
            'formatted_amount' => $member->category?->formattedAmount(),
            'categories' => collect(MemberCategory::cases())->map(fn ($cat) => [
                'value' => $cat->value,
                'label' => $cat->labelWithAmount(),
            ]),
        ]);
    }

    /**
     * Store a newly recorded payment.
     */
    public function store(StorePaymentRequest $request): RedirectResponse
    {
        $recordedBy = $this->user($request);
        $member = User::query()
            ->whereKey($request->integer('member_id'))
            ->firstOrFail();

        $payments = $this->allocationService->allocate(
            member: $member,
            amount: $request->integer('amount'),
            paidAt: $request->string('paid_at')->toString(),
            recordedBy: $recordedBy,
            notes: $request->filled('notes') ? $request->string('notes')->toString() : null,
            targetYear: $request->filled('target_year') ? $request->integer('target_year') : null,
            targetMonth: $request->filled('target_month') ? $request->integer('target_month') : null,
        );

        $totalAllocated = (int) $payments->sum(fn (Payment $payment): int => $payment->amount);
        $formattedAmount = '₦'.number_format($totalAllocated, 2);

        return redirect()->route('dashboard')
            ->with('success', "Payment of {$formattedAmount} recorded for {$member->name}.");
    }

    /**
     * Remove the specified payment (within 24 hours).
     */
    public function destroy(Payment $payment): RedirectResponse
    {
        $this->authorize('delete', $payment);

        $payment->delete();

        return redirect()->back()
            ->with('success', 'Payment has been deleted.');
    }
}
