<?php

declare(strict_types=1);

namespace App\Channels;

use App\Models\User;
use App\Notifications\ContributionReminderNotification;
use App\Services\WhatsAppService;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Custom Laravel notification channel for WhatsApp Business Cloud API.
 *
 * Notifications opt in by including {@see WhatsAppChannel::class} in their
 * via() array and implementing a `toWhatsApp(object $notifiable): WhatsAppMessage`
 * method.
 *
 * The channel resolves the recipient via `routeNotificationFor('whatsapp')`
 * (typically returning the user's verified WhatsApp phone in E.164 format)
 * and skips silently when no number is available. Delivery failures are
 * logged but never thrown — other channels (mail, database) still deliver.
 */
class WhatsAppChannel
{
    public function __construct(
        protected WhatsAppService $whatsapp,
    ) {}

    /**
     * Send the given notification.
     */
    /** @return array{success: bool, wa_message_id: ?string, error: ?string}|null */
    public function send(object $notifiable, Notification $notification): ?array
    {
        if (! method_exists($notifiable, 'routeNotificationFor')) {
            return null;
        }

        $to = $notifiable->routeNotificationFor('whatsapp', $notification);

        if (! is_string($to) || $to === '') {
            return null;
        }

        if (! method_exists($notification, 'toWhatsApp')) {
            return null;
        }

        try {
            $message = $notification->toWhatsApp($notifiable);
        } catch (Throwable $e) {
            Log::error('Failed to build WhatsApp notification message', [
                'notification' => $notification::class,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        if (! $message instanceof WhatsAppMessage) {
            Log::warning('toWhatsApp() must return a WhatsAppMessage instance', [
                'notification' => $notification::class,
            ]);

            return null;
        }

        if ($notification instanceof ContributionReminderNotification && $notifiable instanceof User) {
            return $this->whatsapp->send($this->whatsapp->normalisePhone($to), $message,
                $notification->contribution->family_id, $notifiable->id);
        }

        return $this->whatsapp->send($this->whatsapp->normalisePhone($to), $message);
    }
}
