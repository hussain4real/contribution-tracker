<?php

namespace App\Channels;

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
    public function send(object $notifiable, Notification $notification): void
    {
        $to = $notifiable->routeNotificationFor('whatsapp', $notification);

        if (empty($to)) {
            return;
        }

        if (! method_exists($notification, 'toWhatsApp')) {
            return;
        }

        try {
            $message = $notification->toWhatsApp($notifiable);
        } catch (Throwable $e) {
            Log::error('Failed to build WhatsApp notification message', [
                'notification' => $notification::class,
                'error' => $e->getMessage(),
            ]);

            return;
        }

        if (! $message instanceof WhatsAppMessage) {
            Log::warning('toWhatsApp() must return a WhatsAppMessage instance', [
                'notification' => $notification::class,
            ]);

            return;
        }

        $this->whatsapp->send($this->normalisePhone($to), $message);
    }

    /**
     * Strip non-digit characters so Meta accepts the phone number.
     *
     * Meta expects an E.164 number without the leading "+" or any spaces,
     * dashes, or parentheses (e.g. "2348012345678").
     */
    protected function normalisePhone(string $phone): string
    {
        return preg_replace('/\D+/', '', $phone) ?? '';
    }
}
