<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class PaystackService
{
    private string $baseUrl;

    private string $secretKey;

    public function __construct()
    {
        $baseUrl = config('services.paystack.base_url', 'https://api.paystack.co');
        $secretKey = config('services.paystack.secret_key', '');

        $this->baseUrl = is_string($baseUrl) ? $baseUrl : 'https://api.paystack.co';
        $this->secretKey = is_string($secretKey) ? $secretKey : '';
    }

    /**
     * Initialize a transaction.
     *
     * @param  array{email: string, amount: int, reference?: string, callback_url?: string, subaccount?: string, bearer?: string, metadata?: array<string, mixed>}  $data
     * @return array<string, mixed>
     */
    public function initializeTransaction(array $data): array
    {
        return $this->post('/transaction/initialize', $data);
    }

    /**
     * Verify a transaction by reference.
     *
     * @return array<string, mixed>
     */
    public function verifyTransaction(string $reference): array
    {
        return $this->get("/transaction/verify/{$reference}");
    }

    /**
     * Create a Paystack subaccount for a family.
     *
     * @param  array{business_name: string, bank_code: string, account_number: string, percentage_charge: float}  $data
     * @return array<string, mixed>
     */
    public function createSubaccount(array $data): array
    {
        return $this->post('/subaccount', $data);
    }

    /**
     * Update a Paystack subaccount.
     *
     * @param  array{business_name?: string, bank_code?: string, account_number?: string, percentage_charge?: float}  $data
     * @return array<string, mixed>
     */
    public function updateSubaccount(string $subaccountCode, array $data): array
    {
        return $this->put("/subaccount/{$subaccountCode}", $data);
    }

    /**
     * List available banks.
     *
     * @return array<string, mixed>
     */
    public function listBanks(string $currency = 'NGN'): array
    {
        return $this->get('/bank', ['currency' => $currency, 'perPage' => 100]);
    }

    /**
     * Resolve a bank account number to get account name.
     *
     * @return array<string, mixed>
     */
    public function resolveAccountNumber(string $accountNumber, string $bankCode): array
    {
        return $this->get('/bank/resolve', [
            'account_number' => $accountNumber,
            'bank_code' => $bankCode,
        ]);
    }

    /**
     * Create a subscription plan on Paystack.
     *
     * @param  array{name: string, amount: int, interval: string}  $data
     * @return array<string, mixed>
     */
    public function createPlan(array $data): array
    {
        return $this->post('/plan', $data);
    }

    /**
     * Create a subscription for a customer.
     *
     * @param  array{customer: string, plan: string}  $data
     * @return array<string, mixed>
     */
    public function createSubscription(array $data): array
    {
        return $this->post('/subscription', $data);
    }

    /**
     * Disable (cancel) a subscription.
     *
     * @param  array{code: string, token: string}  $data
     * @return array<string, mixed>
     */
    public function disableSubscription(array $data): array
    {
        return $this->post('/subscription/disable', $data);
    }

    /**
     * Create or fetch a Paystack customer.
     *
     * @param  array{email: string, first_name?: string, last_name?: string}  $data
     * @return array<string, mixed>
     */
    public function createCustomer(array $data): array
    {
        return $this->post('/customer', $data);
    }

    /**
     * Verify a webhook signature.
     */
    public function verifyWebhookSignature(string $payload, string $signature): bool
    {
        $webhookSecret = config('services.paystack.webhook_secret');

        if (! is_string($webhookSecret) || $webhookSecret === '') {
            Log::warning('Paystack webhook secret not configured');

            return false;
        }

        $computed = hash_hmac('sha512', $payload, $webhookSecret);

        return hash_equals($computed, $signature);
    }

    /**
     * Send a GET request to the Paystack API.
     *
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    private function get(string $endpoint, array $query = []): array
    {
        $response = $this->client()->get($this->baseUrl.$endpoint, $query);

        return $this->handleResponse($response, $endpoint);
    }

    /**
     * Send a POST request to the Paystack API.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function post(string $endpoint, array $data = []): array
    {
        $response = $this->client()->post($this->baseUrl.$endpoint, $data);

        return $this->handleResponse($response, $endpoint);
    }

    /**
     * Send a PUT request to the Paystack API.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function put(string $endpoint, array $data = []): array
    {
        $response = $this->client()->put($this->baseUrl.$endpoint, $data);

        return $this->handleResponse($response, $endpoint);
    }

    private function client(): PendingRequest
    {
        return Http::withToken($this->secretKey)
            ->acceptJson()
            ->connectTimeout(10)
            ->timeout(30)
            ->retry(3, 100, fn (Throwable $e): bool => $e instanceof ConnectionException, throw: false);
    }

    /**
     * Handle the API response.
     *
     * @return array<string, mixed>
     *
     * @throws RuntimeException
     */
    private function handleResponse(Response $response, string $endpoint): array
    {
        $body = $this->stringKeyedArray($response->json());

        if ($response->failed()) {
            $message = $body['message'] ?? 'Unknown Paystack API error';
            $message = is_scalar($message) ? (string) $message : 'Unknown Paystack API error';

            Log::error('Paystack API error', [
                'endpoint' => $endpoint,
                'status' => $response->status(),
                'message' => $message,
            ]);

            throw new RuntimeException("Paystack API error: {$message}", $response->status());
        }

        return $body;
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
