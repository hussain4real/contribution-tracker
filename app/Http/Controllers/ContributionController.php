<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\PaymentStatus;
use App\Http\Requests\ContributionRegisterRequest;
use App\Models\Contribution;
use App\Models\ContributionReminderDelivery;
use App\Models\Family;
use App\Models\FamilyMembership;
use App\Models\Payment;
use App\Models\PaymentBatch;
use App\Models\PlatformPlan;
use App\Models\User;
use App\Services\FamilyContributionReviewService;
use App\Support\PlatformPlanCatalog;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Inertia\Inertia;
use Inertia\Response;

class ContributionController extends Controller
{
    /**
     * Display a listing of all contributions (for admins).
     */
    public function index(
        ContributionRegisterRequest $request,
        FamilyContributionReviewService $reviewService,
    ): Response {
        $user = $this->user($request);
        $family = $user->currentFamily ?? $user->family;

        abort_unless($family instanceof Family, 403);

        $filters = $request->filters();

        return Inertia::render('Contributions/Index', [
            'contributions' => $reviewService->register($family, $filters),
            'filters' => $filters,
            'summary' => Inertia::defer(fn (): array => $reviewService->registerSummary($family, $filters)),
            'members' => $family->memberships()->active()->with('user:id,name')->get()
                ->map(fn (FamilyMembership $membership): array => [
                    'id' => $membership->user_id,
                    'name' => $membership->displayName(),
                ])->sortBy('name')->values(),
            'categories' => $family->categories()->orderBy('name')->get(['slug', 'name']),
            'statuses' => collect(PaymentStatus::cases())->map(fn (PaymentStatus $status): array => [
                'value' => $status->value,
                'label' => $status->label(),
            ]),
        ]);
    }

    /**
     * Display the authenticated user's own contributions (FR-015).
     * Shows personal contribution history with family aggregate stats.
     * Does NOT show other members' individual details (FR-016).
     */
    public function my(): Response
    {
        $user = $this->authUser();
        $family = $user->currentFamily ?? $user->family;

        abort_unless($family instanceof Family, 403);

        $contributionModels = Contribution::query()
            ->where('user_id', $user->id)
            ->where('family_id', $family->id)
            ->with(['payments.recorder'])
            ->orderByDesc('year')
            ->orderByDesc('month')
            ->get();

        $contributions = $contributionModels
            ->map(fn (Contribution $contribution) => [
                'id' => $contribution->id,
                'year' => $contribution->year,
                'month' => $contribution->month,
                'expected_amount' => $contribution->expected_amount,
                'total_paid' => $contribution->total_paid,
                'balance' => $contribution->balance,
                'status' => $contribution->status->value,
                'period_label' => $contribution->period_label,
                'due_date' => $contribution->due_date->toDateString(),
                'payments' => $contribution->payments->map(fn (Payment $payment): array => $this->paymentPayload($payment)),
            ]);

        $currentYear = now()->year;
        $currentMonth = now()->month;

        $allContributions = Contribution::query()
            ->where('family_id', $family->id)
            ->where('year', $currentYear)
            ->where('month', $currentMonth)
            ->get();

        $totalExpected = (int) $allContributions->sum(fn (Contribution $contribution): int => $contribution->expected_amount);
        $totalCollected = (int) $allContributions->sum(fn (Contribution $contribution): int => $contribution->total_paid);
        $totalOutstanding = $totalExpected - $totalCollected;
        $collectionRate = $totalExpected > 0
            ? round(($totalCollected / $totalExpected) * 100, 1)
            : 0;

        $personalTotalExpected = (int) $contributionModels->sum(fn (Contribution $contribution): int => $contribution->expected_amount);
        $personalTotalPaid = (int) $contributionModels->sum(fn (Contribution $contribution): int => $contribution->total_paid);
        $personalTotalOutstanding = (int) $contributionModels->sum(fn (Contribution $contribution): int => $contribution->balance);
        $personalPaymentRate = $personalTotalExpected > 0
            ? round(($personalTotalPaid / $personalTotalExpected) * 100, 1)
            : 0;

        $membership = $user->membershipForFamily($family);
        abort_unless($membership instanceof FamilyMembership, 403);
        $nextContribution = $contributionModels->filter(fn (Contribution $contribution): bool => $contribution->balance > 0)
            ->sortBy([['year', 'asc'], ['month', 'asc']])->first();
        $receipts = PaymentBatch::query()
            ->where('family_id', $family->id)
            ->where('family_membership_id', $membership->id)
            ->with(['allocations.contribution', 'reversal'])
            ->orderByDesc('paid_at')->orderByDesc('id')
            ->paginate(20, ['*'], 'receipts_page')
            ->through(fn (PaymentBatch $batch): array => [
                'id' => $batch->id,
                'receipt_number' => $batch->receipt_number,
                'amount' => $batch->total_amount,
                'paid_at' => $batch->paid_at->toDateString(),
                'is_reversed' => $batch->isReversed(),
                'periods' => $batch->allocations->map(fn (Payment $payment): ?string => $payment->contribution?->period_label)
                    ->filter()->unique()->values()->all(),
            ]);
        $family->loadMissing('platformPlan');
        $plan = $family->platformPlan ?? PlatformPlan::query()
            ->where('slug', PlatformPlanCatalog::Free)->where('is_active', true)->first();
        $onlinePaymentsAvailable = (! $plan || in_array(PlatformPlanCatalog::OnlinePayments, $plan->features, true))
            && (! $plan?->isPaid() || ! in_array($family->subscription_status, ['cancelled', 'past_due'], true))
            && $family->hasBankDetails() && filled(config('services.paystack.public_key'));

        return Inertia::render('Contributions/My', [
            'member_id' => $user->id,
            'next_contribution' => $nextContribution ? [
                'period_label' => $nextContribution->period_label,
                'due_date' => $nextContribution->due_date->toDateString(),
                'balance' => $nextContribution->balance,
            ] : null,
            'receipts' => $receipts,
            'online_payments_available' => $onlinePaymentsAvailable,
            'statement_period' => [
                'date_from' => now()->startOfYear()->toDateString(),
                'date_to' => now()->endOfYear()->toDateString(),
            ],
            'contributions' => $contributions,
            'personal_stats' => [
                'total_expected' => $personalTotalExpected,
                'total_paid' => $personalTotalPaid,
                'total_outstanding' => $personalTotalOutstanding,
                'payment_rate' => $personalPaymentRate,
                'contribution_count' => $contributions->count(),
            ],
            'family_aggregate' => [
                'total_expected' => $totalExpected,
                'total_collected' => $totalCollected,
                'total_outstanding' => $totalOutstanding,
                'collection_rate' => $collectionRate,
                'period_label' => now()->format('F Y'),
            ],
        ]);
    }

    /**
     * Display the specified contribution with payment details.
     */
    public function show(Request $request, Contribution $contribution): Response
    {
        $this->authorize('view', $contribution);

        $contribution->load(['user.familyMemberships.familyCategory:id,name,monthly_amount', 'payments.recorder']);
        $contributionUser = $contribution->user;
        $membership = $contributionUser?->membershipForFamilyId($contribution->family_id);
        $canRecord = $this->user($request)->canRecordPayments();
        $reminderDeliveries = $canRecord
            ? ContributionReminderDelivery::query()->where('contribution_id', $contribution->id)
                ->with('whatsappMessage')->latest('id')->limit(30)->get()
                ->map(fn (ContributionReminderDelivery $delivery): array => [
                    'id' => $delivery->id, 'type' => $delivery->reminder_type, 'channel' => $delivery->channel,
                    'status' => $delivery->displayStatus(), 'created_at' => $delivery->created_at?->toIso8601String(),
                    'failure_reason' => $delivery->failureReason(),
                    'can_retry' => $contribution->balance > 0 && in_array($delivery->channel, ['mail', 'whatsapp', 'webpush'], true)
                        && in_array($delivery->displayStatus(), ['failed', 'unconfirmed'], true),
                ])
            : collect();

        return Inertia::render('Contributions/Show', [
            'contribution' => [
                'id' => $contribution->id,
                'year' => $contribution->year,
                'month' => $contribution->month,
                'expected_amount' => $contribution->expected_amount,
                'total_paid' => $contribution->total_paid,
                'balance' => $contribution->balance,
                'status' => $contribution->status->value,
                'period_label' => $contribution->period_label,
                'due_date' => $contribution->due_date->toDateString(),
                'payments' => $contribution->payments->map(fn (Payment $payment): array => $this->paymentPayload($payment)),
                'user' => [
                    'id' => $contributionUser?->id,
                    'name' => $contributionUser?->name,
                    'email' => $contributionUser?->email,
                    'category' => $membership?->categoryLabel(),
                ],
            ],
            'can_record_payment' => $canRecord,
            'reminder_deliveries' => $reminderDeliveries,
        ]);
    }

    /**
     * Generate monthly contributions for the current family.
     */
    public function generate(Request $request): RedirectResponse
    {
        $user = $this->user($request);
        $family = $user->currentFamily ?? $user->family;

        $this->authorize('create', Contribution::class);
        abort_unless($family instanceof Family, 403);

        $year = $this->integerInput($request->input('year'), now()->year);
        $month = $this->integerInput($request->input('month'), now()->month);

        Artisan::call('contributions:generate', [
            '--year' => $year,
            '--month' => $month,
            '--family' => $family->id,
        ]);

        $periodLabel = Carbon::createFromDate($year, $month, 1)->format('F Y');

        return back()->with('success', "Contributions generated for {$periodLabel}.");
    }

    /**
     * @return array{id: int, amount: int, paid_at: string, notes: string|null, recorder: array{id: int|null, name: string|null}, created_at: string|null}
     */
    private function paymentPayload(Payment $payment): array
    {
        $recorder = $payment->recorder;

        return [
            'id' => $payment->id,
            'amount' => $payment->amount,
            'paid_at' => $payment->paid_at->toDateString(),
            'notes' => $payment->notes,
            'recorder' => [
                'id' => $recorder?->id,
                'name' => $recorder?->name,
            ],
            'created_at' => $payment->created_at?->toIso8601String(),
        ];
    }

    private function integerInput(mixed $value, int $default): int
    {
        return is_numeric($value) ? (int) $value : $default;
    }
}
