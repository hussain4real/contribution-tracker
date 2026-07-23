<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\StoreReconciliationLinkRequest;
use App\Models\BankTransaction;
use App\Models\Family;
use App\Models\ReconciliationLink;
use App\Services\ReconciliationLinkService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ReconciliationLinkController extends Controller
{
    public function __construct(private readonly ReconciliationLinkService $links) {}

    public function store(StoreReconciliationLinkRequest $request, BankTransaction $bankTransaction): RedirectResponse
    {
        $this->links->link(
            $bankTransaction,
            $request->string('target_type')->toString(),
            $request->integer('target_id'),
            $request->integer('amount'),
            $this->user($request),
            $request->string('notes')->toString() ?: null,
        );

        return back()->with('success', 'Reconciliation link saved.');
    }

    public function destroy(Request $request, ReconciliationLink $reconciliationLink): RedirectResponse
    {
        $this->authorize('reconcile-family-funds');
        $user = $this->user($request);
        $family = app(Family::class);
        abort_unless($reconciliationLink->family_id === $family->id, 403);
        $this->links->unlink($reconciliationLink, $user);

        return back()->with('success', 'Reconciliation link removed.');
    }
}
