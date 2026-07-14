<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\ReverseFundAdjustment;
use App\Http\Requests\StoreFinancialReversalRequest;
use App\Models\FundAdjustment;
use Illuminate\Http\RedirectResponse;

class FundAdjustmentReversalController extends Controller
{
    public function __invoke(
        StoreFinancialReversalRequest $request,
        FundAdjustment $fundAdjustment,
        ReverseFundAdjustment $reverseFundAdjustment,
    ): RedirectResponse {
        $this->authorize('delete', $fundAdjustment);
        $reverseFundAdjustment->handle(
            $fundAdjustment,
            $this->user($request),
            $request->string('reason')->toString(),
        );

        return redirect()->back()->with('success', 'Fund adjustment reversed. The original record remains in the audit trail.');
    }
}
