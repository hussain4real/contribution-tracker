<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Jobs\ProcessPaystackWebhook;
use App\Services\PaystackService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PaystackWebhookController extends Controller
{
    public function __construct(private readonly PaystackService $paystack) {}

    public function handle(Request $request): JsonResponse
    {
        $payload = $request->getContent();
        $signature = $request->header('X-Paystack-Signature', '');

        if (! $this->paystack->verifyWebhookSignature($payload, $signature)) {
            Log::warning('Paystack webhook rejected an invalid signature.');

            return response()->json(['message' => 'Invalid signature'], 403);
        }

        /** @var array<string, mixed> $validatedPayload */
        $validatedPayload = $request->all();
        ProcessPaystackWebhook::dispatch($validatedPayload);

        return response()->json(['message' => 'Accepted'], 202);
    }
}
