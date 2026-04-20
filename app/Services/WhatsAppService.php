<?php

namespace App\Services;

use App\Channels\WhatsAppMessage;
use App\Models\WhatsAppMessage as WhatsAppMessageModel;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Encapsulates HTTP communication with the Meta WhatsApp Cloud API.
 *
 * All outbound messages are recorded to the whatsapp_messages table for the
 * admin inbox. Failures are logged but never thrown so that callers (such as
 * the notification channel) can continue delivering through other channels.
 */
class WhatsAppService
{
    /**
     * Send a fully-built WhatsAppMessage to the given recipient.
     *
     * @return array{success: bool, wa_message_id: ?string, error: ?string}
     */
    public function send(string $to, WhatsAppMessage $message): array
    {
        $payload = $message->toPayload($to);

        $response = $this->post('messages', $payload);

        $waMessageId = $response['success']
            ? ($response['data']['messages'][0]['id'] ?? null)
            : null;

        $this->recordOutbound(
            to: $to,
            message: $message,
            waMessageId: $waMessageId,
            success: $response['success'],
            error: $response['error'] ?? null,
            errorCode: $response['error_code'] ?? null,
        );

        return [
            'success' => $response['success'],
            'wa_message_id' => $waMessageId,
            'error' => $response['error'] ?? null,
        ];
    }

    /**
     * Send an approved template message.
     *
     * @param  array<int, string|int|float>  $bodyParameters
     * @return array{success: bool, wa_message_id: ?string, error: ?string}
     */
    public function sendTemplate(string $to, string $templateName, array $bodyParameters = [], string $languageCode = 'en'): array
    {
        $message = (new WhatsAppMessage)
            ->template($templateName, $languageCode)
            ->body($bodyParameters);

        return $this->send($to, $message);
    }

    /**
     * Send a plain text message. Only valid inside the 24h customer service window.
     *
     * @return array{success: bool, wa_message_id: ?string, error: ?string}
     */
    public function sendText(string $to, string $body): array
    {
        $message = (new WhatsAppMessage)->text($body);

        return $this->send($to, $message);
    }

    /**
     * POST a payload to the Graph API and normalise the response.
     *
     * @param  array<string, mixed>  $payload
     * @return array{success: bool, data: array<string, mixed>, error: ?string, error_code: ?string}
     */
    protected function post(string $endpoint, array $payload): array
    {
        $config = config('services.whatsapp');

        $url = sprintf(
            '%s/%s/%s/%s',
            rtrim($config['base_url'], '/'),
            $config['api_version'],
            $config['phone_number_id'],
            $endpoint,
        );

        try {
            $response = Http::withToken($config['access_token'])
                ->acceptJson()
                ->asJson()
                ->timeout(15)
                ->post($url, $payload);
        } catch (Throwable $e) {
            Log::error('WhatsApp request failed', [
                'endpoint' => $endpoint,
                'message' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'data' => [],
                'error' => $e->getMessage(),
                'error_code' => null,
            ];
        }

        $data = $response->json() ?? [];

        if ($response->failed()) {
            $error = $data['error']['message'] ?? 'WhatsApp API request failed';
            $errorCode = isset($data['error']['code']) ? (string) $data['error']['code'] : null;

            Log::warning('WhatsApp API returned an error', [
                'endpoint' => $endpoint,
                'status' => $response->status(),
                'error' => $error,
                'error_code' => $errorCode,
            ]);

            return [
                'success' => false,
                'data' => $data,
                'error' => $error,
                'error_code' => $errorCode,
            ];
        }

        return [
            'success' => true,
            'data' => $data,
            'error' => null,
            'error_code' => null,
        ];
    }

    /**
     * Persist an outbound message to the inbox table.
     */
    protected function recordOutbound(
        string $to,
        WhatsAppMessage $message,
        ?string $waMessageId,
        bool $success,
        ?string $error,
        ?string $errorCode,
    ): void {
        if (! class_exists(WhatsAppMessageModel::class)) {
            return;
        }

        try {
            WhatsAppMessageModel::create([
                'wa_message_id' => $waMessageId,
                'direction' => 'outbound',
                'from' => config('services.whatsapp.phone_number_id'),
                'to' => $to,
                'type' => $message->getKind(),
                'body' => $message->getKind() === 'text' ? $message->getTextBody() : null,
                'template_name' => $message->getKind() === 'template' ? $message->getTemplateName() : null,
                'payload' => $message->toPayload($to),
                'status' => $success ? 'sent' : 'failed',
                'error_code' => $errorCode,
                'error_message' => $error,
                'wa_timestamp' => now(),
            ]);
        } catch (Throwable $e) {
            Log::warning('Failed to record outbound WhatsApp message', [
                'to' => $to,
                'message' => $e->getMessage(),
            ]);
        }
    }
}
