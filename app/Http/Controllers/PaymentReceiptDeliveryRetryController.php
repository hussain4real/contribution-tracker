<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Jobs\SendPaymentReceipt;
use App\Models\Family;
use App\Models\PaymentReceiptDelivery;
use App\Models\User;
use App\Models\WhatsAppMessage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PaymentReceiptDeliveryRetryController extends Controller
{
    public function __invoke(Request $request, PaymentReceiptDelivery $delivery): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->canRecordPayments(), 403);
        $family = $user->currentFamily ?? $user->family;
        $delivery->load(['paymentBatch.membership.user', 'paymentBatch.reversal', 'whatsappMessage']);
        $batch = $delivery->paymentBatch;
        abort_unless($family instanceof Family && $batch->family_id === $family->id, 404);
        $membership = $batch->membership;
        $member = $membership?->user;
        if (! $member instanceof User || $membership->isArchived() || $batch->isReversed()
            || ! in_array($delivery->channel, ['mail', 'whatsapp'], true)
            || ! in_array($delivery->displayStatus(), ['failed', 'unconfirmed'], true)) {
            return back()->with('error', 'This delivery cannot currently be retried.');
        }

        $queued = DB::transaction(function () use ($delivery, $batch, $member): bool {
            $query = PaymentReceiptDelivery::query()->whereKey($delivery->id)
                ->where('status', $delivery->status)
                ->where('provider_message_id', $delivery->provider_message_id);
            if ($delivery->status === 'accepted') {
                $query->whereIn('provider_message_id', WhatsAppMessage::query()->where('status', 'failed')->select('wa_message_id'));
            }
            $updated = $query->update([
                'status' => 'queued', 'provider_message_id' => null,
                'attempted_at' => null, 'accepted_at' => null, 'failed_at' => null,
            ]);
            if ($updated !== 1) {
                return false;
            }
            SendPaymentReceipt::dispatch($batch->id, $member->id, $delivery->channel);

            return true;
        });

        return back()->with($queued ? 'success' : 'error', $queued
            ? 'Receipt delivery queued again. The payment was not changed.'
            : 'Delivery status changed. Refresh and check it before retrying.');
    }
}
