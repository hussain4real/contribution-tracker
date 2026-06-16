<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Contribution;
use App\Models\Family;
use App\Models\Payment;
use App\Models\User;
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
    public function index(): Response
    {
        // TODO: Implement with pagination and filters
        return Inertia::render('Contributions/Index');
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
        $personalTotalOutstanding = $personalTotalExpected - $personalTotalPaid;
        $personalPaymentRate = $personalTotalExpected > 0
            ? round(($personalTotalPaid / $personalTotalExpected) * 100, 1)
            : 0;

        return Inertia::render('Contributions/My', [
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
            'can_record_payment' => $this->user($request)->canRecordPayments(),
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
