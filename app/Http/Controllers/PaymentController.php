<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\PaymentMethod;
use App\Enums\PaymentSource;
use App\Http\Requests\StorePaymentRequest;
use App\Models\Family;
use App\Models\FamilyMembership;
use App\Models\Payment;
use App\Models\PaymentBatch;
use App\Models\User;
use App\Services\PaymentAllocationService;
use App\Support\CurrencyFormatter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Str;
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
                    'name' => $membership->displayName(),
                    'email' => $member->email,
                    'category' => $membership->category?->value,
                    'category_label' => $membership->categoryLabel(),
                    'monthly_amount' => $membership->monthlyAmount() ?? 0,
                ];
            });

        $receipts = PaymentBatch::query()
            ->where('family_id', $family->id)
            ->with(['recorder:id,name', 'reversal:id,reversible_type,reversible_id,reason'])
            ->withCount('allocations')
            ->latest('paid_at')
            ->latest('id')
            ->limit(50)
            ->get()
            ->map(fn (PaymentBatch $batch): array => [
                'id' => $batch->id,
                'receipt_number' => $batch->receipt_number,
                'member_name' => $batch->member_name,
                'total_amount' => $batch->total_amount,
                'paid_at' => $batch->paid_at->toDateString(),
                'method' => $batch->method->label(),
                'source' => $batch->source->label(),
                'reference' => $batch->reference,
                'recorded_by' => $batch->recorder?->name,
                'allocations_count' => $batch->allocations_count,
                'is_reversed' => $batch->isReversed(),
                'reversal_reason' => $batch->reversal?->reason,
                'can_reverse' => $currentUser->can('reverse', $batch),
            ]);

        return Inertia::render('Payments/Index', [
            'members' => $members,
            'receipts' => $receipts,
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
                'name' => $membership->displayName(),
                'email' => $member->email,
                'category' => $membership->category?->value,
                'category_label' => $membership->categoryLabel(),
            ],
            'pending_contributions' => $pendingContributions,
            'category_amount' => $membership->monthlyAmount() ?? 0,
            'formatted_amount' => CurrencyFormatter::format($membership->monthlyAmount() ?? 0, $currency),
            'categories' => $family->categories()
                ->orderBy('sort_order')
                ->get(['id', 'name', 'monthly_amount'])
                ->map(fn ($category): array => [
                    'value' => $category->id,
                    'label' => "{$category->name} (".CurrencyFormatter::format($category->monthly_amount, $currency, 0).'/month)',
                ]),
            'paymentMethods' => collect(PaymentMethod::cases())->map(fn (PaymentMethod $method): array => [
                'value' => $method->value,
                'label' => $method->label(),
            ]),
            'idempotencyKey' => (string) Str::uuid(),
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

        $batch = $this->allocationService->createBatch(
            member: $member,
            amount: $request->integer('amount'),
            paidAt: $request->string('paid_at')->toString(),
            recordedBy: $recordedBy,
            notes: $request->filled('notes') ? $request->string('notes')->toString() : null,
            targetYear: $request->filled('target_year') ? $request->integer('target_year') : null,
            targetMonth: $request->filled('target_month') ? $request->integer('target_month') : null,
            family: $family,
            method: PaymentMethod::from($request->string('method', PaymentMethod::Cash->value)->toString()),
            source: PaymentSource::Manual,
            reference: $request->filled('reference') ? $request->string('reference')->toString() : null,
            idempotencyKey: $request->filled('idempotency_key')
                ? $request->string('idempotency_key')->toString()
                : (string) Str::uuid(),
        );

        $currency = $family->currency;
        $formattedAmount = CurrencyFormatter::format($batch->total_amount, $currency);

        return redirect()->route('dashboard')
            ->with('success', "Receipt #{$batch->receipt_number}: {$formattedAmount} recorded for {$batch->member_name}.");
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
            ->active()
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
