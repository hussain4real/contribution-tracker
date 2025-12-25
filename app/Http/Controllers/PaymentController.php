<?php

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
     * Display a listing of all payments.
     */
    public function index(): Response
    {
        $payments = Payment::with(['contribution.user', 'recorder'])
            ->latestFirst()
            ->paginate(20);

        return Inertia::render('Payments/Index', [
            'payments' => $payments,
        ]);
    }

    /**
     * Show the form for recording a new payment for a member.
     */
    public function create(User $member): Response
    {
        $this->authorize('create', Payment::class);

        // Generate available months (current + next 6 months) per FR-018
        $availableMonths = collect();
        $currentDate = now()->startOfMonth();

        for ($i = 0; $i <= 6; $i++) {
            $date = $currentDate->copy()->addMonths($i);
            $availableMonths->push([
                'year' => $date->year,
                'month' => $date->month,
                'label' => $date->format('F Y'),
            ]);
        }

        // Get member's pending contributions
        $pendingContributions = $member->contributions()
            ->incomplete()
            ->oldestFirst()
            ->get();

        return Inertia::render('Payments/Create', [
            'member' => $member->only(['id', 'name', 'email', 'category']),
            'available_months' => $availableMonths,
            'pending_contributions' => $pendingContributions,
            'category_amount' => $member->category?->monthlyAmountInKobo(),
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
        $member = User::findOrFail($request->member_id);

        $payments = $this->allocationService->allocate(
            member: $member,
            amount: $request->amount,
            paidAt: $request->paid_at,
            recordedBy: $request->user(),
            notes: $request->notes,
            targetYear: $request->target_year,
            targetMonth: $request->target_month,
        );

        $totalAllocated = $payments->sum('amount');
        $formattedAmount = '₦'.number_format($totalAllocated / 100, 2);

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
