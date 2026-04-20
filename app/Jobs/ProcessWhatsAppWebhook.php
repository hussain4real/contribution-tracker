<?php

namespace App\Jobs;

use App\Models\User;
use App\Models\WhatsAppMessage;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Process a single WhatsApp webhook payload from Meta.
 *
 * Handles three event groups:
 * - statuses[]                       — outbound message delivery status updates
 * - messages[]                       — inbound messages from end users
 * - message_template_status_update   — template approval / rejection notices
 */
class ProcessWhatsAppWebhook implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(public array $payload) {}

    public function handle(): void
    {
        $entries = $this->payload['entry'] ?? [];

        if (! is_array($entries)) {
            return;
        }

        foreach ($entries as $entry) {
            $changes = $entry['changes'] ?? [];

            if (! is_array($changes)) {
                continue;
            }

            foreach ($changes as $change) {
                $field = $change['field'] ?? null;
                $value = $change['value'] ?? [];

                if (! is_array($value)) {
                    continue;
                }

                match ($field) {
                    'messages' => $this->handleMessagesField($value),
                    'message_template_status_update' => $this->handleTemplateStatusUpdate($value),
                    default => null,
                };
            }
        }
    }

    /**
     * The "messages" field carries both inbound messages and outbound status updates.
     *
     * @param  array<string, mixed>  $value
     */
    protected function handleMessagesField(array $value): void
    {
        foreach (($value['statuses'] ?? []) as $status) {
            if (is_array($status)) {
                $this->updateOutboundStatus($status);
            }
        }

        foreach (($value['messages'] ?? []) as $message) {
            if (is_array($message)) {
                $this->recordInboundMessage($message, $value);
            }
        }
    }

    /**
     * Update an outbound message's delivery status.
     *
     * @param  array<string, mixed>  $status
     */
    protected function updateOutboundStatus(array $status): void
    {
        $waMessageId = $status['id'] ?? null;
        $newStatus = $status['status'] ?? null;

        if (! is_string($waMessageId) || ! is_string($newStatus)) {
            return;
        }

        try {
            $message = WhatsAppMessage::query()->where('wa_message_id', $waMessageId)->first();

            if (! $message) {
                return;
            }

            $errors = $status['errors'] ?? [];
            $firstError = is_array($errors) && isset($errors[0]) && is_array($errors[0]) ? $errors[0] : null;

            $message->update([
                'status' => $newStatus,
                'error_code' => isset($firstError['code']) ? (string) $firstError['code'] : $message->error_code,
                'error_message' => $firstError['title'] ?? $firstError['message'] ?? $message->error_message,
            ]);
        } catch (Throwable $e) {
            Log::warning('Failed to update outbound WhatsApp status', [
                'wa_message_id' => $waMessageId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Record an inbound message from a user.
     *
     * @param  array<string, mixed>  $message
     * @param  array<string, mixed>  $value
     */
    protected function recordInboundMessage(array $message, array $value): void
    {
        $waMessageId = $message['id'] ?? null;
        $from = $message['from'] ?? null;
        $type = $message['type'] ?? 'text';
        $timestamp = $message['timestamp'] ?? null;

        if (! is_string($waMessageId) || ! is_string($from)) {
            return;
        }

        if (WhatsAppMessage::query()->where('wa_message_id', $waMessageId)->exists()) {
            return;
        }

        $body = match ($type) {
            'text' => $message['text']['body'] ?? null,
            'button' => $message['button']['text'] ?? null,
            'interactive' => $message['interactive']['button_reply']['title']
                ?? $message['interactive']['list_reply']['title']
                ?? null,
            default => null,
        };

        $user = $this->matchUser($from);

        try {
            WhatsAppMessage::create([
                'wa_message_id' => $waMessageId,
                'direction' => 'inbound',
                'from' => $from,
                'to' => $value['metadata']['phone_number_id'] ?? config('services.whatsapp.phone_number_id'),
                'type' => is_string($type) ? $type : 'text',
                'body' => is_string($body) ? $body : null,
                'template_name' => null,
                'payload' => $message,
                'status' => 'received',
                'family_id' => $user?->family_id,
                'user_id' => $user?->id,
                'wa_timestamp' => is_numeric($timestamp) ? now()->createFromTimestamp((int) $timestamp) : now(),
            ]);
        } catch (Throwable $e) {
            Log::warning('Failed to record inbound WhatsApp message', [
                'wa_message_id' => $waMessageId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Match an inbound phone number to a user via their verified WhatsApp phone.
     *
     * Compares using digit-only forms so that "+234..." and "234..." both match.
     * Uses a database-level regex strip so we don't pull every verified user
     * into memory.
     */
    protected function matchUser(string $from): ?User
    {
        $normalised = preg_replace('/\D+/', '', $from) ?? '';

        if ($normalised === '') {
            return null;
        }

        $driver = DB::connection()->getDriverName();

        $query = User::query()
            ->whereNotNull('whatsapp_verified_at')
            ->whereNotNull('whatsapp_phone');

        if ($driver === 'pgsql') {
            return $query
                ->whereRaw("regexp_replace(whatsapp_phone, '\\D', '', 'g') = ?", [$normalised])
                ->first();
        }

        if ($driver === 'mysql' || $driver === 'mariadb') {
            return $query
                ->whereRaw("REGEXP_REPLACE(whatsapp_phone, '[^0-9]', '') = ?", [$normalised])
                ->first();
        }

        // Fallback (e.g. SQLite in tests) — no regex_replace; do digit-stripped
        // PHP comparison but keep the result set bounded by the indexed columns.
        return $query
            ->get()
            ->first(function (User $user) use ($normalised): bool {
                $userDigits = preg_replace('/\D+/', '', (string) $user->whatsapp_phone) ?? '';

                return $userDigits !== '' && $userDigits === $normalised;
            });
    }

    /**
     * Log template approval / rejection / disable events from Meta.
     *
     * @param  array<string, mixed>  $value
     */
    protected function handleTemplateStatusUpdate(array $value): void
    {
        Log::info('WhatsApp template status update', [
            'template_name' => $value['message_template_name'] ?? null,
            'event' => $value['event'] ?? null,
            'reason' => $value['reason'] ?? null,
        ]);
    }
}
