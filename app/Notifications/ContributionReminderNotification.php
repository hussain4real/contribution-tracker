<?php

namespace App\Notifications;

use App\Channels\WhatsAppChannel;
use App\Channels\WhatsAppMessage;
use App\Models\Contribution;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\Attributes\MaxExceptions;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Queue\Middleware\RateLimited;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

#[Tries(5)]
#[Timeout(60)]
#[MaxExceptions(3)]
class ContributionReminderNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * Optional channel restriction. When set, only these channels are used.
     *
     * @var array<int, string>|null
     */
    protected ?array $channels = null;

    /**
     * Create a new notification instance.
     *
     * @param  'reminder'|'follow_up'  $type
     */
    public function __construct(
        public Contribution $contribution,
        public string $type = 'reminder',
    ) {
        $this->contribution->loadMissing(['family', 'payments']);
    }

    /**
     * Restrict the notification to the given channels (e.g. ['whatsapp']).
     *
     * Accepts the friendly aliases 'mail', 'database', and 'whatsapp', which
     * are mapped to the underlying driver/class identifiers.
     *
     * @param  array<int, string>  $channels
     */
    public function onlyChannels(array $channels): self
    {
        $this->channels = array_map(
            fn (string $channel): string => match ($channel) {
                'whatsapp' => WhatsAppChannel::class,
                'webpush', 'web_push' => WebPushChannel::class,
                default => $channel,
            },
            $channels,
        );

        return $this;
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return $this->channels ?? ['mail', 'database', WhatsAppChannel::class, WebPushChannel::class];
    }

    /**
     * Get the middleware the queued notification job should pass through.
     *
     * @return array<int, object>
     */
    public function middleware(object $notifiable, string $channel): array
    {
        if ($channel !== WhatsAppChannel::class) {
            return [];
        }

        return [(new RateLimited('whatsapp-notifications'))->releaseAfter(60)];
    }

    /**
     * Calculate the number of seconds to wait before retrying the notification.
     *
     * @return list<int>
     */
    public function backoff(): array
    {
        return [1, 5, 10];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $subject = $this->type === 'follow_up'
            ? "Follow-up: Your {$this->contribution->period_label} contribution is due today"
            : "Reminder: Your {$this->contribution->period_label} contribution is due soon";

        return (new MailMessage)
            ->subject($subject)
            ->markdown('mail.contribution-reminder', [
                'contribution' => $this->contribution,
                'type' => $this->type,
                'userName' => $notifiable->name,
                'familyName' => $this->contribution->family->name,
            ]);
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'contribution_id' => $this->contribution->id,
            'family_name' => $this->contribution->family->name,
            'period_label' => $this->contribution->period_label,
            'amount_owed' => $this->contribution->balance,
            'due_date' => $this->contribution->due_date->toDateString(),
            'type' => $this->type,
        ];
    }

    /**
     * Get the WhatsApp representation of the notification.
     *
     * Sends the approved `contribution_reminder` template with the user's name,
     * reminder type, period, family name, and remaining balance.
     */
    public function toWhatsApp(object $notifiable): WhatsAppMessage
    {
        $reminderType = $this->type === 'follow_up' ? 'follow-up' : 'reminder';

        return (new WhatsAppMessage)
            ->template('contribution_reminder', 'en')
            ->body([
                $notifiable->name,
                $reminderType,
                $this->contribution->period_label,
                $this->contribution->family->name,
                $this->contribution->formattedBalance(),
            ]);
    }

    /**
     * Get the web push representation of the notification.
     */
    public function toWebPush(object $notifiable, Notification $notification): WebPushMessage
    {
        $isFollowUp = $this->type === 'follow_up';
        $title = $isFollowUp ? 'Contribution due today' : 'Contribution due soon';
        $body = "Your {$this->contribution->period_label} contribution for {$this->contribution->family->name} has {$this->contribution->formattedBalance()} remaining.";

        return (new WebPushMessage)
            ->title($title)
            ->body($body)
            ->icon('/pwa-192x192.png')
            ->badge('/pwa-192x192.png')
            ->tag("contribution-{$this->contribution->id}-{$this->type}")
            ->data([
                'notification_id' => $notification->id,
                'contribution_id' => $this->contribution->id,
                'url' => route('contributions.show', $this->contribution),
                'type' => $this->type,
            ])
            ->options(['TTL' => 60 * 60 * 24]);
    }
}
