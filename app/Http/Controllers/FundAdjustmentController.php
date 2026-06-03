<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\StoreFundAdjustmentRequest;
use App\Models\FundAdjustment;
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

        $adjustments = FundAdjustment::query()
            ->where('family_id', $user->family_id)
            ->with('recorder')
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
        $amount = $request->integer('amount');

        FundAdjustment::create([
            'family_id' => $user->family_id,
            'amount' => $amount,
            'description' => $request->string('description')->toString(),
            'recorded_at' => $request->string('recorded_at')->toString(),
            'recorded_by' => $user->id,
        ]);

        $formattedAmount = '₦'.number_format($amount, 2);

        return redirect()->route('fund-adjustments.index')
            ->with('success', "Fund adjustment of {$formattedAmount} recorded successfully.");
    }

    /**
     * Remove the specified fund adjustment.
     */
    public function destroy(FundAdjustment $fundAdjustment): RedirectResponse
    {
        $this->authorize('delete', $fundAdjustment);

        $fundAdjustment->delete();

        return redirect()->route('fund-adjustments.index')
            ->with('success', 'Fund adjustment has been deleted.');
    }
}
