<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\ReversePaymentBatch;
use App\Http\Requests\StoreFinancialReversalRequest;
use App\Models\PaymentBatch;
use Illuminate\Http\RedirectResponse;

class PaymentBatchReversalController extends Controller
{
    public function __invoke(
        StoreFinancialReversalRequest $request,
        PaymentBatch $paymentBatch,
        ReversePaymentBatch $reversePaymentBatch,
    ): RedirectResponse {
        $this->authorize('reverse', $paymentBatch);
        $reversePaymentBatch->handle($paymentBatch, $this->user($request), $request->string('reason')->toString());

        return redirect()->back()->with('success', 'Payment receipt reversed. The original record remains in the audit trail.');
    }
}
