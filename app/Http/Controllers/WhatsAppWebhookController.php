<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Jobs\ProcessWhatsAppWebhook;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Handles incoming webhooks from Meta WhatsApp Business Cloud API.
 *
 * Two endpoints:
 * - GET /webhooks/whatsapp  — Meta verification handshake (returns hub.challenge).
 * - POST /webhooks/whatsapp — incoming events (statuses + messages + template updates).
 *
 * The POST endpoint must respond within 5 seconds, so heavy processing is
 * dispatched to a queued job.
 */
class WhatsAppWebhookController extends Controller
{
    /**
     * Handle Meta's webhook verification request.
     */
    public function verify(Request $request): Response
    {
        $mode = $request->query('hub_mode');
        $token = $request->query('hub_verify_token');
        $challenge = $request->query('hub_challenge');

        $expectedToken = config('services.whatsapp.webhook_verify_token');

        if ($mode === 'subscribe' && is_string($token) && is_string($expectedToken) && hash_equals($expectedToken, $token)) {
            return response((string) $challenge, HttpResponse::HTTP_OK)
                ->header('Content-Type', 'text/plain');
        }

        Log::warning('WhatsApp webhook verification failed', [
            'mode' => $mode,
            'token_present' => $token !== null,
        ]);

        return response('Forbidden', HttpResponse::HTTP_FORBIDDEN);
    }

    /**
     * Handle an incoming WhatsApp webhook event.
     */
    public function handle(Request $request): JsonResponse
    {
        $appSecret = config('services.whatsapp.app_secret');
        $signatureHeader = $request->header('X-Hub-Signature-256', '');
        $rawBody = $request->getContent();

        if (! is_string($rawBody)) {
            $rawBody = '';
        }

        if (! is_string($signatureHeader)) {
            $signatureHeader = '';
        }

        if (! empty($appSecret) && ! $this->verifySignature($rawBody, $signatureHeader, (string) $appSecret)) {
            Log::warning('WhatsApp webhook: invalid signature');

            return response()->json(['message' => 'Invalid signature'], HttpResponse::HTTP_FORBIDDEN);
        }

        $payload = $request->all();

        if (($payload['object'] ?? null) !== 'whatsapp_business_account') {
            return response()->json(['message' => 'Ignored']);
        }

        ProcessWhatsAppWebhook::dispatch($payload);

        return response()->json(['message' => 'Received']);
    }

    /**
     * Verify Meta's HMAC SHA-256 signature.
     */
    protected function verifySignature(string $rawBody, string $signatureHeader, string $appSecret): bool
    {
        if (! str_starts_with($signatureHeader, 'sha256=')) {
            return false;
        }

        $providedSignature = substr($signatureHeader, 7);
        $expectedSignature = hash_hmac('sha256', $rawBody, $appSecret);

        return hash_equals($expectedSignature, $providedSignature);
    }
}
