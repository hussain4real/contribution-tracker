<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\ReopenReconciliationPeriodRequest;
use App\Http\Requests\StoreReconciliationPeriodRequest;
use App\Models\Family;
use App\Models\ReconciliationPeriod;
use App\Services\ReconciliationPeriodService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ReconciliationPeriodController extends Controller
{
    public function __construct(private readonly ReconciliationPeriodService $periods) {}

    public function store(StoreReconciliationPeriodRequest $request): RedirectResponse
    {
        $user = $this->user($request);
        $family = $user->currentFamily ?? $user->family;
        abort_unless($family instanceof Family, 403);
        $this->periods->create($family, $request->string('starts_at')->toString(), $request->string('ends_at')->toString());

        return back()->with('success', 'Reconciliation period opened.');
    }

    public function close(Request $request, ReconciliationPeriod $reconciliationPeriod): RedirectResponse
    {
        $this->authorize('reconcile-family-funds');
        $user = $this->user($request);
        $family = app(Family::class);
        abort_unless($reconciliationPeriod->family_id === $family->id, 403);
        $this->periods->close($reconciliationPeriod, $user);

        return back()->with('success', 'Reconciliation period closed with an immutable snapshot.');
    }

    public function reopen(ReopenReconciliationPeriodRequest $request, ReconciliationPeriod $reconciliationPeriod): RedirectResponse
    {
        $this->periods->reopen($reconciliationPeriod, $this->user($request), $request->string('reason')->toString());

        return back()->with('success', 'Reconciliation period reopened. Its closed snapshot remains preserved.');
    }
}
