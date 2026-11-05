<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Channels\WhatsAppChannel;
use App\Models\ContributionReminderDelivery;
use App\Models\Family;
use App\Models\User;
use App\Models\WhatsAppMessage;
use App\Notifications\ContributionReminderNotification;
use App\Services\TransactionalNotificationQueue;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use NotificationChannels\WebPush\WebPushChannel;

class ContributionReminderDeliveryRetryController extends Controller
{
    public function __invoke(Request $request, ContributionReminderDelivery $delivery, TransactionalNotificationQueue $queue): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->canRecordPayments(), 403);
        $family = $user->currentFamily ?? $user->family;
        $delivery->load(['contribution.user', 'contribution.family', 'contribution.payments', 'whatsappMessage']);
        $contribution = $delivery->contribution;
        abort_unless($family instanceof Family && $contribution->family_id === $family->id, 404);
        $member = $contribution->user;
        if (! $member instanceof User || $contribution->balance <= 0 || $family->isArchived()
            || ! in_array($delivery->reminder_type, ['reminder', 'follow_up'], true)
            || ! in_array($delivery->channel, ['mail', 'whatsapp', 'webpush'], true)
            || ! in_array($delivery->displayStatus(), ['failed', 'unconfirmed'], true)) {
            return back()->with('error', 'This reminder cannot currently be retried.');
        }
        $channel = match ($delivery->channel) {
            'whatsapp' => WhatsAppChannel::class,
            'webpush' => WebPushChannel::class,
            default => 'mail',
        };
        $notification = (new ContributionReminderNotification($contribution, $delivery->reminder_type))
            ->onlyChannels([$delivery->channel])->trackDeliveries([$channel => $delivery->id])
            ->onConnection($queue->connection())->beforeCommit();
        if (! in_array($channel, $notification->via($member), true)) {
            return back()->with('error', 'The member or family has disabled this reminder channel.');
        }

        $queued = DB::transaction(function () use ($delivery, $member, $notification): bool {
            $query = ContributionReminderDelivery::query()->whereKey($delivery->id)->where('status', $delivery->status)
                ->where('provider_message_id', $delivery->provider_message_id);
            if ($delivery->status === 'accepted') {
                $query->whereIn('provider_message_id', WhatsAppMessage::query()->where('status', 'failed')->select('wa_message_id'));
            }
            if ($query->update(['status' => 'queued', 'provider_message_id' => null,
                'attempted_at' => null, 'accepted_at' => null, 'failed_at' => null]) !== 1) {
                return false;
            }
            $member->notify($notification);

            return true;
        });

        return back()->with($queued ? 'success' : 'error', $queued
            ? 'Reminder delivery queued again.'
            : 'Delivery status changed. Refresh and check it before retrying.');
    }
}
