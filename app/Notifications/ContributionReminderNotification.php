<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Channels\WhatsAppChannel;
use App\Channels\WhatsAppMessage;
use App\Models\Contribution;
use App\Models\ContributionReminderDelivery;
use App\Models\Family;
use App\Models\PlatformPlan;
use App\Models\User;
use App\Support\CurrencyFormatter;
use App\Support\PlatformPlanCatalog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\Attributes\MaxExceptions;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Queue\Middleware\RateLimited;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;
use Throwable;

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

    /** @var array<string, int> */
    private array $deliveryIds = [];

    private ?string $activeChannel = null;

    /** @param array<string, int> $deliveries */
    public function trackDeliveries(array $deliveries): self
    {
        $this->deliveryIds = $deliveries;

        return $this;
    }

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
     * Web push is intentionally part of the default app-notification channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        if (! $notifiable instanceof User) {
            return [];
        }
        $membership = $notifiable->membershipForFamilyId($this->contribution->family_id);
        if ($membership === null) {
            return [];
        }
        $preferences = $membership->reminder_channels ?? ['mail', 'whatsapp', 'webpush'];
        $familyChannels = $this->contribution->family?->reminderSettingsForMonth(now('UTC')->format('Y-m'))['channels']
            ?? ['mail', 'whatsapp', 'webpush'];
        $preferences = array_values(array_intersect($preferences, $familyChannels));
        $family = $this->contribution->family;
        if ($family instanceof Family) {
            $family->loadMissing('platformPlan');
            $plan = $family->platformPlan ?? PlatformPlan::query()->where('slug', PlatformPlanCatalog::Free)->where('is_active', true)->first();
            if ($plan instanceof PlatformPlan) {
                $features = ['mail' => PlatformPlanCatalog::EmailReminders, 'whatsapp' => PlatformPlanCatalog::WhatsappReminders, 'webpush' => PlatformPlanCatalog::WebPushReminders];
                $preferences = array_values(array_filter($preferences, fn (string $channel): bool => isset($features[$channel]) && in_array($features[$channel], $plan->features, true)));
                if ($plan->isPaid() && in_array($family->subscription_status, ['cancelled', 'past_due'], true)) {
                    $preferences = [];
                }
            }
        }
        $allowed = ['database'];
        foreach ($preferences as $preference) {
            $allowed[] = match ($preference) {
                'whatsapp' => WhatsAppChannel::class,
                'webpush' => WebPushChannel::class,
                default => $preference,
            };
        }

        return array_values(array_intersect($this->channels ?? ['mail', 'database', WhatsAppChannel::class, WebPushChannel::class], $allowed));
    }

    public function shouldSend(object $notifiable, string $channel): bool
    {
        $deliveryId = $this->deliveryIds[$channel] ?? null;
        if ($deliveryId !== null && ! ContributionReminderDelivery::query()->whereKey($deliveryId)->where('status', 'queued')->exists()) {
            return false;
        }

        if (! $notifiable instanceof User) {
            $this->updateDelivery($channel, ['status' => 'unavailable']);

            return false;
        }

        $contribution = Contribution::query()
            ->whereKey($this->contribution->id)
            ->where('user_id', $notifiable->id)
            ->incomplete()
            ->with(['family', 'payments'])
            ->first();

        if (! $contribution || ! $contribution->family || $contribution->family->isArchived()
            || ! $notifiable->membershipForFamily($contribution->family)) {
            $this->updateDelivery($channel, ['status' => 'unavailable']);

            return false;
        }

        $this->contribution = $contribution;

        if (! in_array($channel, $this->via($notifiable), true)) {
            $this->updateDelivery($channel, ['status' => 'unavailable']);

            return false;
        }
        $this->updateDelivery($channel, ['attempted_at' => now()]);

        return true;
    }

    public function afterSending(object $notifiable, string $channel, mixed $response): void
    {
        if ($channel === WhatsAppChannel::class) {
            $id = is_array($response) && is_string($response['wa_message_id'] ?? null) ? $response['wa_message_id'] : null;
            $status = $response === null ? 'unavailable' : (is_array($response) && ($response['success'] ?? false) === true && $id !== null ? 'accepted' : 'failed');
            $this->updateDelivery($channel, ['status' => $status, 'provider_message_id' => $id,
                'accepted_at' => $status === 'accepted' ? now() : null, 'failed_at' => $status === 'failed' ? now() : null]);

            return;
        }
        if ($notifiable instanceof User && (($channel === 'mail' && blank($notifiable->email))
            || ($channel === WebPushChannel::class && ! $notifiable->pushSubscriptions()->exists()))) {
            $this->updateDelivery($channel, ['status' => 'unavailable']);

            return;
        }
        $this->updateDelivery($channel, ['status' => $channel === WebPushChannel::class ? 'submitted' : 'accepted', 'accepted_at' => now()]);
    }

    public function failed(?Throwable $exception): void
    {
        if ($this->activeChannel !== null) {
            $this->updateDelivery($this->activeChannel, ['status' => 'failed', 'failed_at' => now()]);
        }
    }

    /** @param array<string, mixed> $attributes */
    private function updateDelivery(string $channel, array $attributes): void
    {
        $id = $this->deliveryIds[$channel] ?? null;
        if ($id !== null) {
            ContributionReminderDelivery::query()->whereKey($id)->where('status', 'queued')->update($attributes);
        }
    }

    /**
     * Get the middleware the queued notification job should pass through.
     *
     * @return array<int, object>
     */
    public function middleware(object $notifiable, string $channel): array
    {
        $this->activeChannel = $channel;
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
        $balances = $this->outstandingBalances();
        $currency = $this->contribution->family?->currency;

        $subject = $this->type === 'follow_up'
            ? "Follow-up: Your {$this->contribution->period_label} contribution"
            : "Reminder: Your {$this->contribution->period_label} contribution";

        return (new MailMessage)
            ->subject($subject)
            ->markdown('mail.contribution-reminder', [
                'contribution' => $this->contribution,
                'contributionsUrl' => route('contributions.my', [
                    'current_family' => $this->familySlug(),
                ]),
                'type' => $this->type,
                'userName' => $this->notifiableName($notifiable),
                'familyName' => $this->familyName(),
                'previousBalance' => CurrencyFormatter::format($balances['previous'], $currency),
                'remainingBalance' => CurrencyFormatter::format($balances['total'], $currency),
            ]);
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        $balances = $this->outstandingBalances();

        return [
            'contribution_id' => $this->contribution->id,
            'family_name' => $this->familyName(),
            'period_label' => $this->contribution->period_label,
            'amount_owed' => $balances['total'],
            'total_outstanding' => $balances['total'],
            'due_date' => $this->contribution->due_date->toDateString(),
            'type' => $this->type,
        ];
    }

    /**
     * Get the WhatsApp representation of the notification.
     *
     * Sends the approved `contribution_reminder` template with the user's name,
     * reminder type, period, family name, and total outstanding balance.
     */
    public function toWhatsApp(object $notifiable): WhatsAppMessage
    {
        $reminderType = $this->type === 'follow_up' ? 'follow-up' : 'reminder';

        return (new WhatsAppMessage)
            ->template('contribution_reminder', 'en')
            ->body([
                $this->notifiableName($notifiable),
                $reminderType,
                $this->contribution->period_label,
                $this->familyName(),
                $this->formattedOutstandingBalance(),
            ]);
    }

    /**
     * Get the web push representation of the notification.
     */
    public function toWebPush(object $notifiable, Notification $notification): WebPushMessage
    {
        $isFollowUp = $this->type === 'follow_up';
        $title = $isFollowUp ? 'Contribution follow-up' : 'Contribution reminder';
        $due = $this->contribution->due_date->toFormattedDateString();
        $body = "Your {$this->contribution->period_label} contribution for {$this->familyName()} is due on {$due}. Total outstanding, including previous months: {$this->formattedOutstandingBalance()}.";

        return (new WebPushMessage)
            ->title($title)
            ->body($body)
            ->icon('/pwa-192x192.png')
            ->badge('/pwa-192x192.png')
            ->tag("contribution-{$this->contribution->id}-{$this->type}")
            ->data([
                'notification_id' => $notification->id,
                'contribution_id' => $this->contribution->id,
                'url' => route('contributions.show', [
                    'current_family' => $this->familySlug(),
                    'contribution' => $this->contribution,
                ]),
                'type' => $this->type,
            ])
            ->options(['TTL' => 60 * 60 * 24]);
    }

    /**
     * @return array{previous: int, total: int}
     */
    private function outstandingBalances(): array
    {
        $previous = (int) Contribution::query()
            ->where('family_id', $this->contribution->family_id)
            ->forUser($this->contribution->user_id)
            ->where(function (Builder $query): void {
                $query->where('year', '<', $this->contribution->year)
                    ->orWhere(function (Builder $query): void {
                        $query->where('year', $this->contribution->year)
                            ->where('month', '<', $this->contribution->month);
                    });
            })
            ->with('payments')
            ->get()
            ->sum(fn (Contribution $contribution): int => $contribution->balance);

        return [
            'previous' => $previous,
            'total' => $previous + $this->contribution->balance,
        ];
    }

    private function formattedOutstandingBalance(): string
    {
        return CurrencyFormatter::format($this->outstandingBalances()['total'], $this->contribution->family?->currency);
    }

    private function notifiableName(object $notifiable): string
    {
        return $notifiable instanceof User ? $notifiable->name : 'there';
    }

    private function familyName(): string
    {
        $family = $this->contribution->family;

        return $family instanceof Family ? $family->name : 'your family';
    }

    private function familySlug(): string
    {
        $family = $this->contribution->family;

        return $family instanceof Family ? $family->slug : (string) $this->contribution->family_id;
    }
}
