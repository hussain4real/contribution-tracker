<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\StoreFundAdjustmentRequest;
use App\Models\FundAdjustment;
use App\Support\CurrencyFormatter;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class FundAdjustmentController extends Controller
{
    /**
     * Display a listing of all fund adjustments with a form to create new ones.
     */
    public function index(): Response
    {
        $this->authorize('viewAny', FundAdjustment::class);

        $user = $this->authUser();
        $family = $user->currentFamily ?? $user->family;

        abort_unless($family !== null, 403);

        $adjustments = FundAdjustment::query()
            ->where('family_id', $family->id)
            ->with(['recorder', 'reversal'])
            ->latestFirst()
            ->latest('id')
            ->paginate(20)
            ->through(fn (FundAdjustment $adjustment) => [
                'id' => $adjustment->id,
                'amount' => $adjustment->amount,
                'description' => $adjustment->description,
                'recorded_at' => $adjustment->recorded_at->toDateString(),
                'recorded_by' => $adjustment->recorder?->name,
                'created_at' => $adjustment->created_at?->toDateString(),
                'is_reversed' => $adjustment->isReversed(),
                'reversal_reason' => $adjustment->reversal?->reason,
                'can_reverse' => $user->can('delete', $adjustment) && ! $adjustment->isReversed(),
            ]);

        return Inertia::render('FundAdjustments/Index', [
            'adjustments' => $adjustments,
            'can_create' => $user->canRecordPayments(),
        ]);
    }

    /**
     * Store a newly created fund adjustment.
     */
    public function store(StoreFundAdjustmentRequest $request): RedirectResponse
    {
        $user = $this->user($request);
        $family = $user->currentFamily ?? $user->family;

        abort_unless($family !== null, 403);
        $amount = $request->integer('amount');

        FundAdjustment::create([
            'family_id' => $family->id,
            'amount' => $amount,
            'description' => $request->string('description')->toString(),
            'recorded_at' => $request->string('recorded_at')->toString(),
            'recorded_by' => $user->id,
        ]);

        $formattedAmount = CurrencyFormatter::format($amount, $family->currency);

        return redirect()->route('fund-adjustments.index')
            ->with('success', "Fund adjustment of {$formattedAmount} recorded successfully.");
    }
}
