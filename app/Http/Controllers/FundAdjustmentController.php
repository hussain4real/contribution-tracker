<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreFundAdjustmentRequest;
use App\Models\FundAdjustment;
use App\Models\User;
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

        /** @var User $user */
        $user = request()->user();

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
                'created_at' => $adjustment->created_at->toDateString(),
            ]);

        return Inertia::render('FundAdjustments/Index', [
            'adjustments' => $adjustments,
            'can_create' => request()->user()->isAdmin(),
        ]);
    }

    /**
     * Store a newly created fund adjustment.
     */
    public function store(StoreFundAdjustmentRequest $request): RedirectResponse
    {
        FundAdjustment::create([
            'family_id' => $request->user()->family_id,
            'amount' => $request->amount,
            'description' => $request->description,
            'recorded_at' => $request->recorded_at,
            'recorded_by' => $request->user()->id,
        ]);

        $formattedAmount = '₦'.number_format($request->amount, 2);

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
