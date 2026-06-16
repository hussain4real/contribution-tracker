<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\MemberCategory;
use App\Http\Requests\StorePaymentRequest;
use App\Models\Family;
use App\Models\FamilyMembership;
use App\Models\Payment;
use App\Models\User;
use App\Services\PaymentAllocationService;
use App\Support\CurrencyFormatter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
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
        $family = $this->currentFamilyFor($currentUser);

        $members = $this->payingMemberships($family)
            ->map(function (FamilyMembership $membership): array {
                $member = $membership->user;

                return [
                    'id' => $member->id,
                    'name' => $member->name,
                    'email' => $member->email,
                    'category' => $membership->category?->value,
                    'category_label' => $membership->categoryLabel(),
                    'monthly_amount' => $membership->monthlyAmount() ?? 0,
                ];
            });

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
        $family = $this->currentFamilyFor($this->authUser());
        $membership = $this->membershipForMember($member, $family);
        $currency = $family->currency;

        // Get member's pending (incomplete) contributions
        $pendingContributions = $member->contributions()
            ->where('family_id', $family->id)
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
            'member' => [
                'id' => $member->id,
                'name' => $member->name,
                'email' => $member->email,
                'category' => $membership->category?->value,
                'category_label' => $membership->categoryLabel(),
            ],
            'pending_contributions' => $pendingContributions,
            'category_amount' => $membership->monthlyAmount() ?? 0,
            'formatted_amount' => CurrencyFormatter::format($membership->monthlyAmount() ?? 0, $currency),
            'categories' => collect(MemberCategory::cases())->map(fn ($cat) => [
                'value' => $cat->value,
                'label' => "{$cat->label()} (".CurrencyFormatter::format($cat->monthlyAmount(), $currency, 0).'/month)',
            ]),
        ]);
    }

    /**
     * Store a newly recorded payment.
     */
    public function store(StorePaymentRequest $request): RedirectResponse
    {
        $recordedBy = $this->user($request);
        $family = $this->currentFamilyFor($recordedBy);
        $member = User::query()
            ->whereKey($request->integer('member_id'))
            ->firstOrFail();
        $this->membershipForMember($member, $family);

        $payments = $this->allocationService->allocate(
            member: $member,
            amount: $request->integer('amount'),
            paidAt: $request->string('paid_at')->toString(),
            recordedBy: $recordedBy,
            notes: $request->filled('notes') ? $request->string('notes')->toString() : null,
            targetYear: $request->filled('target_year') ? $request->integer('target_year') : null,
            targetMonth: $request->filled('target_month') ? $request->integer('target_month') : null,
            family: $family,
        );

        $totalAllocated = (int) $payments->sum(fn (Payment $payment): int => $payment->amount);
        $currency = $family->currency;
        $formattedAmount = CurrencyFormatter::format($totalAllocated, $currency);

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

    private function currentFamilyFor(User $user): Family
    {
        $family = $user->currentFamily ?? $user->family;

        abort_unless($family instanceof Family, 403);

        return $family;
    }

    private function membershipForMember(User $member, Family $family): FamilyMembership
    {
        $membership = $member->membershipForFamily($family);

        abort_unless($membership instanceof FamilyMembership, 404);

        return $membership;
    }

    /**
     * @return EloquentCollection<int, FamilyMembership>
     */
    private function payingMemberships(Family $family): EloquentCollection
    {
        return $family->memberships()
            ->with(['familyCategory:id,name,monthly_amount', 'user'])
            ->whereHas('user', function (Builder $query): void {
                $query->whereNull('archived_at');
            })
            ->where(function (Builder $query): void {
                $query->whereNotNull('family_members.family_category_id')
                    ->orWhereNotNull('family_members.category');
            })
            ->join('users', 'users.id', '=', 'family_members.user_id')
            ->orderBy('users.name')
            ->select('family_members.*')
            ->get();
    }
}
