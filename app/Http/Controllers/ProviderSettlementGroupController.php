<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\StoreProviderSettlementGroupRequest;
use App\Models\BankTransaction;
use App\Models\Family;
use App\Services\ProviderSettlementService;
use Illuminate\Http\RedirectResponse;

class ProviderSettlementGroupController extends Controller
{
    public function __construct(private readonly ProviderSettlementService $settlements) {}

    public function store(StoreProviderSettlementGroupRequest $request): RedirectResponse
    {
        $user = $this->user($request);
        $family = $user->currentFamily ?? $user->family;
        abort_unless($family instanceof Family, 403);
        $bankId = $request->integer('bank_transaction_id');
        $bankTransaction = $bankId > 0 ? BankTransaction::query()->where('family_id', $family->id)->findOrFail($bankId) : null;
        $this->settlements->create(
            $family->id,
            $request->transactionIds(),
            $request->string('reference')->toString(),
            $request->string('settled_at')->toString(),
            $user,
            $bankTransaction,
            $request->string('notes')->toString() ?: null,
        );

        return back()->with('success', 'Paystack settlement group created and reconciled.');
    }
}
