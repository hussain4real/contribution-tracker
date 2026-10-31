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
use App\Models\PaymentReceiptDelivery;
use App\Models\User;
use App\Services\PaymentAllocationPreview;
use App\Services\PaymentAllocationService;
use App\Support\CurrencyFormatter;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

class PaymentController extends Controller
{
    public function __construct(
        private PaymentAllocationService $allocationService
    ) {}

    /**
     * Display member selection for recording a payment.
     */
    public function index(Request $request): Response
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
            ->with(['recorder:id,name', 'reversal:id,reversible_type,reversible_id,reason', 'receiptDeliveries.whatsappMessage'])
            ->withCount('allocations')
            ->orderByRaw('case when id = ? then 0 else 1 end', [$request->integer('receipt')])
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
                'deliveries' => $batch->receiptDeliveries->map(fn (PaymentReceiptDelivery $delivery): array => [
                    'id' => $delivery->id,
                    'channel' => $delivery->channel,
                    'status' => $delivery->displayStatus(),
                    'failure_reason' => $delivery->failureReason(),
                    'can_retry' => ! $batch->isReversed() && in_array($delivery->displayStatus(), ['failed', 'unconfirmed'], true),
                ])->values(),
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

    public function review(StorePaymentRequest $request, PaymentAllocationPreview $preview): JsonResponse
    {
        if (! $request->filled('idempotency_key')) {
            throw ValidationException::withMessages(['idempotency_key' => 'A payment request key is required.']);
        }
        $user = $this->user($request);
        $family = $this->currentFamilyFor($user);
        $member = User::query()->findOrFail($request->integer('member_id'));
        $membership = $this->membershipForMember($member, $family);
        try {
            $result = $preview->preview($member, $family, $membership, $request->integer('amount'),
                $request->filled('target_year') ? $request->integer('target_year') : null,
                $request->filled('target_month') ? $request->integer('target_month') : null);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['amount' => $exception->getMessage()]);
        }
        $reference = $request->string('reference')->toString();
        $duplicate = $reference !== '' && PaymentBatch::query()->where('family_id', $family->id)
            ->where('reference', $reference)->exists();
        $payload = ['fingerprint' => $this->reviewFingerprint($request, $family, $user),
            'expires_at' => now()->addMinutes(15)->timestamp, 'allocations' => $result['allocations']];

        return response()->json([...$result, 'possible_duplicate' => $duplicate,
            'token' => Crypt::encryptString(json_encode($payload, JSON_THROW_ON_ERROR))]);
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
        if (! $request->filled('review_token') || ! $request->filled('idempotency_key')) {
            throw ValidationException::withMessages(['review' => 'Review the payment before recording it.']);
        }
        $review = $this->reviewedAllocations($request, $family, $recordedBy);

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
            idempotencyKey: $request->string('idempotency_key')->toString(),
            expectedAllocations: $review['allocations'],
            reviewExpiresAt: $review['expires_at'],
        );

        $currency = $family->currency;
        $formattedAmount = CurrencyFormatter::format($batch->total_amount, $currency);
        $message = $batch->isReversed()
            ? "Receipt #{$batch->receipt_number} for {$formattedAmount} was recorded and later reversed."
            : "Receipt #{$batch->receipt_number}: {$formattedAmount} recorded for {$batch->member_name}.";

        return redirect()->route('payments.index', ['receipt' => $batch->id])
            ->with('success', $message);
    }

    private function reviewFingerprint(StorePaymentRequest $request, Family $family, User $user): string
    {
        return hash('sha256', json_encode([
            $family->id, $user->id, $request->integer('member_id'), $request->integer('amount'),
            $request->string('paid_at')->toString(), $request->string('method', PaymentMethod::Cash->value)->toString(),
            $request->input('reference'), $request->input('notes'),
            $request->filled('target_year') ? $request->integer('target_year') : null,
            $request->filled('target_month') ? $request->integer('target_month') : null,
            $request->input('idempotency_key'),
        ], JSON_THROW_ON_ERROR));
    }

    /** @return array{allocations: list<array{year: int, month: int, period: string, amount: int}>, expires_at: int} */
    private function reviewedAllocations(StorePaymentRequest $request, Family $family, User $user): array
    {
        try {
            $payload = json_decode(Crypt::decryptString($request->string('review_token')->toString()), true, flags: JSON_THROW_ON_ERROR);
        } catch (DecryptException|\JsonException) {
            throw ValidationException::withMessages(['review' => 'The payment review is invalid. Review it again.']);
        }
        if (! is_array($payload) || ! is_string($payload['fingerprint'] ?? null)
            || ! hash_equals($this->reviewFingerprint($request, $family, $user), $payload['fingerprint'])
            || ! is_int($payload['expires_at'] ?? null)
            || ! is_array($payload['allocations'] ?? null) || ! array_is_list($payload['allocations'])) {
            throw ValidationException::withMessages(['review' => 'The payment details changed or the review expired. Review it again.']);
        }
        $allocations = [];
        foreach ($payload['allocations'] as $item) {
            if (! is_array($item) || ! is_int($item['year'] ?? null) || ! is_int($item['month'] ?? null)
                || ! is_string($item['period'] ?? null) || ! is_int($item['amount'] ?? null)) {
                throw ValidationException::withMessages(['review' => 'The payment review is invalid. Review it again.']);
            }
            $allocations[] = ['year' => $item['year'], 'month' => $item['month'], 'period' => $item['period'], 'amount' => $item['amount']];
        }

        return ['allocations' => $allocations, 'expires_at' => $payload['expires_at']];
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
