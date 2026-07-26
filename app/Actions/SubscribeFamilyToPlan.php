<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Models\Family;
use App\Models\PaystackTransaction;
use App\Models\PlatformPlan;
use App\Models\User;
use App\Services\PaystackService;
use Illuminate\Support\Str;
use RuntimeException;

class SubscribeFamilyToPlan
{
    public function __construct(
        private PaystackService $paystack
    ) {}

    /**
     * Subscribe a family to a paid platform plan via Paystack.
     *
     * @return array{access_code: string, authorization_url: string, reference: string}
     *
     * @throws RuntimeException
     */
    public function execute(User $user, Family $family, PlatformPlan $plan): array
    {
        $this->ensurePaystackPlanExists($plan);
        $this->ensurePaystackCustomerExists($user, $family);

        $reference = 'SUB_'.Str::upper(Str::random(16));

        PaystackTransaction::create([
            'reference' => $reference,
            'user_id' => $user->id,
            'family_id' => $family->id,
            'type' => TransactionType::Subscription,
            'amount' => $plan->price,
            'status' => TransactionStatus::Pending,
            'metadata' => [
                'plan_id' => $plan->id,
                'plan_slug' => $plan->slug,
            ],
        ]);

        $response = $this->paystack->initializeTransaction([
            'email' => $user->email,
            'amount' => $plan->price * 100,
            'reference' => $reference,
            'plan' => $plan->paystack_plan_code,
            'callback_url' => route('subscription.callback'),
            'metadata' => [
                'family_id' => $family->id,
                'plan_id' => $plan->id,
                'type' => 'subscription',
            ],
        ]);

        $data = $this->responseData($response);
        $accessCode = $this->requiredString($data, 'access_code');
        $authorizationUrl = $this->requiredString($data, 'authorization_url');

        return [
            'access_code' => $accessCode,
            'authorization_url' => $authorizationUrl,
            'reference' => $reference,
        ];
    }

    private function ensurePaystackPlanExists(PlatformPlan $plan): void
    {
        if ($plan->paystack_plan_code) {
            return;
        }

        $response = $this->paystack->createPlan([
            'name' => $plan->name.' - FamilyFund',
            'amount' => $plan->price * 100,
            'interval' => 'monthly',
        ]);

        $plan->update([
            'paystack_plan_code' => $this->requiredString($this->responseData($response), 'plan_code'),
        ]);
    }

    private function ensurePaystackCustomerExists(User $user, Family $family): void
    {
        if ($family->paystack_customer_code) {
            return;
        }

        $response = $this->paystack->createCustomer([
            'email' => $user->email,
            'first_name' => $user->name,
        ]);

        $family->update([
            'paystack_customer_code' => $this->requiredString($this->responseData($response), 'customer_code'),
        ]);
    }

    /**
     * @param  array<string, mixed>  $response
     * @return array<string, mixed>
     */
    private function responseData(array $response): array
    {
        $data = $response['data'] ?? null;

        if (! is_array($data)) {
            throw new RuntimeException('Paystack response did not include a data payload.');
        }

        $items = [];

        foreach ($data as $key => $value) {
            if (is_string($key)) {
                $items[$key] = $value;
            }
        }

        return $items;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function requiredString(array $data, string $key): string
    {
        $value = $data[$key] ?? null;

        if (! is_string($value) || $value === '') {
            throw new RuntimeException("Paystack response did not include {$key}.");
        }

        return $value;
    }
}
