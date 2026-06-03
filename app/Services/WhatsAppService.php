<?php

declare(strict_types=1);

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

        $messages = $response['data']['messages'] ?? null;
        $firstMessage = is_array($messages) && isset($messages[0]) && is_array($messages[0]) ? $messages[0] : [];
        $waMessageId = $response['success'] && is_string($firstMessage['id'] ?? null)
            ? $firstMessage['id']
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
     * Send a family invitation using a pre-approved WhatsApp template.
     *
     * @return array{success: bool, wa_message_id: ?string, error: ?string}
     */
    public function sendInvitation(string $to, string $familyName, string $roleLabel, string $acceptUrl): array
    {
        $template = $this->invitationTemplate();

        if ($template === null) {
            Log::warning('WhatsApp invitation template is not configured');

            return [
                'success' => false,
                'wa_message_id' => null,
                'error' => 'WhatsApp invitation template is not configured.',
            ];
        }

        return $this->sendTemplate(
            to: $this->normalisePhone($to),
            templateName: $template['name'],
            bodyParameters: [$familyName, $roleLabel, $acceptUrl],
            languageCode: $template['language'],
        );
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
     * Strip non-digit characters so Meta accepts the phone number.
     *
     * Meta expects an E.164 number without the leading "+" or any spaces,
     * dashes, or parentheses (e.g. "2348012345678").
     */
    public function normalisePhone(string $phone): string
    {
        return preg_replace('/\D+/', '', $phone) ?? '';
    }

    /**
     * POST a payload to the Graph API and normalise the response.
     *
     * @param  array<string, mixed>  $payload
     * @return array{success: bool, data: array<string, mixed>, error: ?string, error_code: ?string}
     */
    protected function post(string $endpoint, array $payload): array
    {
        $config = $this->config();

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

        $data = $this->stringKeyedArray($response->json());

        if ($response->failed()) {
            $errorData = $this->stringKeyedArray($data['error'] ?? null);
            $error = $errorData['message'] ?? 'WhatsApp API request failed';
            $error = is_scalar($error) ? (string) $error : 'WhatsApp API request failed';
            $errorCode = isset($errorData['code']) && is_scalar($errorData['code']) ? (string) $errorData['code'] : null;

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
        try {
            $phoneNumberId = config('services.whatsapp.phone_number_id');

            WhatsAppMessageModel::create([
                'wa_message_id' => $waMessageId,
                'direction' => 'outbound',
                'from' => is_scalar($phoneNumberId) ? (string) $phoneNumberId : '',
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

    /**
     * @return array{base_url: string, api_version: string, phone_number_id: string, access_token: string}
     */
    private function config(): array
    {
        $config = config('services.whatsapp');
        $config = is_array($config) ? $config : [];

        return [
            'base_url' => is_string($config['base_url'] ?? null) ? $config['base_url'] : 'https://graph.facebook.com',
            'api_version' => is_string($config['api_version'] ?? null) ? $config['api_version'] : 'v20.0',
            'phone_number_id' => is_string($config['phone_number_id'] ?? null) ? $config['phone_number_id'] : '',
            'access_token' => is_string($config['access_token'] ?? null) ? $config['access_token'] : '',
        ];
    }

    /**
     * @return array{name: string, language: string}|null
     */
    private function invitationTemplate(): ?array
    {
        $config = config('services.whatsapp.templates.invitation');
        $config = is_array($config) ? $config : [];

        $name = is_string($config['name'] ?? null) ? trim($config['name']) : '';

        if ($name === '') {
            return null;
        }

        $language = is_string($config['language'] ?? null) ? trim($config['language']) : '';

        return [
            'name' => $name,
            'language' => $language !== '' ? $language : 'en',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function stringKeyedArray(mixed $value): array
    {
        $items = [];

        foreach (is_array($value) ? $value : [] as $key => $item) {
            if (is_string($key)) {
                $items[$key] = $item;
            }
        }

        return $items;
    }
}
