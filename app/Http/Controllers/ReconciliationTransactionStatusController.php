<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\ReconciliationStatus;
use App\Http\Requests\UpdateReconciliationTransactionStatusRequest;
use App\Models\BankTransaction;
use App\Services\ReconciliationStatusService;
use Illuminate\Http\RedirectResponse;

class ReconciliationTransactionStatusController extends Controller
{
    public function __construct(private readonly ReconciliationStatusService $statuses) {}

    public function update(UpdateReconciliationTransactionStatusRequest $request, BankTransaction $bankTransaction): RedirectResponse
    {
        $this->statuses->update(
            $bankTransaction,
            ReconciliationStatus::from($request->string('status')->toString()),
            $this->user($request),
            $request->string('reason')->toString() ?: null,
        );

        return back()->with('success', 'Transaction status updated.');
    }
}
