<?php

namespace App\Http\Controllers;

use App\Models\Contribution;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
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
        /** @var User $user */
        $user = Auth::user();

        // Get user's own contributions with payments, ordered by date descending
        $contributions = Contribution::query()
            ->where('user_id', $user->id)
            ->with(['payments.recorder'])
            ->orderByDesc('year')
            ->orderByDesc('month')
            ->get()
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
                'payments' => $contribution->payments->map(fn ($payment) => [
                    'id' => $payment->id,
                    'amount' => $payment->amount,
                    'paid_at' => $payment->paid_at->toDateString(),
                    'notes' => $payment->notes,
                    'recorder' => [
                        'id' => $payment->recorder->id,
                        'name' => $payment->recorder->name,
                    ],
                    'created_at' => $payment->created_at->toIso8601String(),
                ]),
            ]);

        // Calculate family aggregate statistics (FR-015)
        // Members can see aggregate totals but NOT individual details (FR-016)
        $currentYear = now()->year;
        $currentMonth = now()->month;

        $allContributions = Contribution::query()
            ->where('year', $currentYear)
            ->where('month', $currentMonth)
            ->get();

        $totalExpected = $allContributions->sum('expected_amount');
        $totalCollected = $allContributions->sum('total_paid');
        $totalOutstanding = $totalExpected - $totalCollected;
        $collectionRate = $totalExpected > 0
            ? round(($totalCollected / $totalExpected) * 100, 1)
            : 0;

        return Inertia::render('Contributions/My', [
            'contributions' => $contributions,
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

        $contribution->load(['user', 'payments.recorder']);

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
                'payments' => $contribution->payments->map(fn ($payment) => [
                    'id' => $payment->id,
                    'amount' => $payment->amount,
                    'paid_at' => $payment->paid_at->toDateString(),
                    'notes' => $payment->notes,
                    'recorder' => [
                        'id' => $payment->recorder->id,
                        'name' => $payment->recorder->name,
                    ],
                    'created_at' => $payment->created_at->toIso8601String(),
                ]),
                'user' => [
                    'id' => $contribution->user->id,
                    'name' => $contribution->user->name,
                    'email' => $contribution->user->email,
                    'category' => $contribution->user->category?->label(),
                ],
            ],
            'can_record_payment' => $request->user()->canRecordPayments(),
        ]);
    }
}
