<?php

declare(strict_types=1);

namespace App\Services;

use App\Channels\WhatsAppChannel;
use App\Models\ContributionReminderDelivery;
use App\Models\User;
use App\Notifications\ContributionReminderNotification;
use Illuminate\Support\Facades\DB;
use NotificationChannels\WebPush\WebPushChannel;

class QueueContributionReminder
{
    public function queue(User $member, ContributionReminderNotification $notification): void
    {
        DB::transaction(function () use ($member, $notification): void {
            $deliveries = [];
            foreach ($notification->via($member) as $channel) {
                $name = match ($channel) {
                    WhatsAppChannel::class => 'whatsapp',
                    WebPushChannel::class => 'webpush',
                    default => $channel,
                };
                $delivery = ContributionReminderDelivery::query()->create([
                    'contribution_id' => $notification->contribution->id,
                    'reminder_type' => $notification->type,
                    'channel' => $name,
                    'status' => 'queued',
                ]);
                $deliveries[$channel] = $delivery->id;
            }
            $member->notify($notification->trackDeliveries($deliveries)->onConnection(app(TransactionalNotificationQueue::class)->connection())->beforeCommit());
        });
    }
}
