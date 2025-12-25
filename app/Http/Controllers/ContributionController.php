<?php

namespace App\Http\Controllers;

use App\Models\Contribution;
use Illuminate\Http\Request;
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
     * Display the authenticated user's own contributions.
     */
    public function my(): Response
    {
        // TODO: Implement personal contribution history
        return Inertia::render('Contributions/My');
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
