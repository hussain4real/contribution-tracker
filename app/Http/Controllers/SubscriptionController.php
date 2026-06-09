<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\SubscribeFamilyToPlan;
use App\Enums\TransactionStatus;
use App\Http\Requests\SubscribePlanRequest;
use App\Models\Family;
use App\Models\PaystackTransaction;
use App\Models\PlatformPlan;
use App\Services\PaystackService;
use App\Support\PlatformPlanCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

class SubscriptionController extends Controller
{
    public function __construct(
        private PaystackService $paystack
    ) {}

    /**
     * Show subscription plans and current family subscription status.
     */
    public function index(): Response
    {
        $user = $this->authUser();
        $family = $user->family;
        $currentPlan = $family?->platformPlan;

        if (! $currentPlan && $family instanceof Family) {
            $currentPlan = PlatformPlan::query()
                ->where('slug', PlatformPlanCatalog::Free)
                ->where('is_active', true)
                ->first();
        }

        $plans = PlatformPlan::where('is_active', true)
            ->orderBy('sort_order')
            ->get()
            ->map(function (PlatformPlan $plan) use ($currentPlan): array {
                $metadata = PlatformPlanCatalog::subscriptionCardMetadata()[$plan->slug] ?? [
                    'audience' => 'FamilyFunds workspace',
                    'summary' => 'A custom package configured by the platform team.',
                    'is_recommended' => false,
                ];

                return [
                    'id' => $plan->id,
                    'name' => $plan->name,
                    'slug' => $plan->slug,
                    'price' => $plan->price,
                    'formatted_price' => $plan->formattedPrice(),
                    'max_members' => $plan->max_members,
                    'features' => $plan->features ?? [],
                    'is_current' => $currentPlan?->id === $plan->id,
                    ...$metadata,
                ];
            });

        return Inertia::render('Subscription/Index', [
            'plans' => $plans,
            'current_plan' => $currentPlan?->only(['id', 'name', 'slug', 'price']),
            'subscription_status' => $family instanceof Family ? ($family->subscription_status ?? 'free') : 'free',
            'current_period_end' => $family?->current_period_end?->toDateString(),
            'member_count' => $family?->members()->count() ?? 0,
            'is_admin' => $user->isAdmin(),
            'paystack_public_key' => $this->stringConfig('services.paystack.public_key'),
            'available_features' => PlatformPlanCatalog::featureLabels(),
        ]);
    }

    /**
     * Subscribe the family to a paid plan via Paystack.
     */
    public function subscribe(SubscribePlanRequest $request, SubscribeFamilyToPlan $action): JsonResponse
    {
        $validated = $request->validated();

        $user = $this->authUser();
        $family = $user->family;

        if (! $family) {
            return response()->json([
                'message' => 'You must belong to a family to subscribe.',
            ], 422);
        }

        $plan = PlatformPlan::query()
            ->whereKey($request->integer('plan_id'))
            ->firstOrFail();

        if ($plan->isFree()) {
            return response()->json([
                'message' => 'Cannot subscribe to the free plan via payment.',
            ], 422);
        }

        try {
            $result = $action->execute($user, $family, $plan);

            return response()->json($result);
        } catch (\RuntimeException $e) {
            Log::error('Subscription failed', ['error' => $e->getMessage()]);

            return response()->json([
                'message' => 'Failed to initialize subscription. Please try again.',
            ], 500);
        }
    }

    /**
     * Handle the Paystack callback after subscription payment.
     */
    public function callback(Request $request): RedirectResponse
    {
        $reference = $request->query('reference');

        if (! is_string($reference) || $reference === '') {
            return redirect()->route('subscription.index')
                ->with('error', 'No payment reference received.');
        }

        $transaction = PaystackTransaction::where('reference', $reference)->first();

        if (! $transaction) {
            return redirect()->route('subscription.index')
                ->with('error', 'Payment reference not found.');
        }

        try {
            $verification = $this->paystack->verifyTransaction($reference);

            $verificationData = $this->stringKeyedArray($verification['data'] ?? null);

            if (($verificationData['status'] ?? null) === 'success') {
                // Atomic conditional update to prevent race condition with webhook
                $updated = PaystackTransaction::where('reference', $reference)
                    ->where('status', TransactionStatus::Pending)
                    ->update([
                        'status' => TransactionStatus::Success,
                        'paystack_response' => json_encode($verificationData),
                    ]);

                if ($updated === 0) {
                    // Already processed by webhook — redirect gracefully
                    return redirect()->route('subscription.index')
                        ->with('success', 'Subscription is already active.');
                }

                // Update family subscription
                $family = Family::find($transaction->family_id);
                $metadata = $transaction->metadata ?? [];
                $planId = $metadata['plan_id'] ?? null;

                if ($family && is_numeric($planId)) {
                    $paidAt = $verificationData['paid_at'] ?? null;

                    $family->update([
                        'platform_plan_id' => (int) $planId,
                        'subscription_status' => 'active',
                        'current_period_end' => is_scalar($paidAt) ? now()->parse((string) $paidAt)->addMonth() : null,
                    ]);
                }

                return redirect()->route('subscription.index')
                    ->with('success', 'Subscription activated successfully!');
            }

            return redirect()->route('subscription.index')
                ->with('error', 'Payment was not successful. Please try again.');
        } catch (\RuntimeException $e) {
            Log::error('Failed to verify subscription transaction', ['error' => $e->getMessage()]);

            return redirect()->route('subscription.index')
                ->with('error', 'Could not verify payment. Please contact support if you were charged.');
        }
    }

    /**
     * Cancel the current family subscription.
     */
    public function cancel(): RedirectResponse
    {
        $user = $this->authUser();

        if (! $user->isAdmin()) {
            abort(403, 'Only family admins can manage subscriptions.');
        }

        $family = $user->family;

        if (! $family || ! $family->paystack_subscription_code || ! $family->paystack_subscription_email_token) {
            return redirect()->route('subscription.index')
                ->with('error', 'No active subscription to cancel.');
        }

        try {
            $this->paystack->disableSubscription([
                'code' => $family->paystack_subscription_code,
                'token' => $family->paystack_subscription_email_token,
            ]);

            $family->update([
                'subscription_status' => 'cancelled',
            ]);

            return redirect()->route('subscription.index')
                ->with('success', 'Subscription cancelled. You will retain access until the end of the current billing period.');
        } catch (\RuntimeException $e) {
            Log::error('Failed to cancel subscription', ['error' => $e->getMessage()]);

            return redirect()->route('subscription.index')
                ->with('error', 'Failed to cancel subscription. Please try again.');
        }
    }

    private function stringConfig(string $key): string
    {
        $value = config($key);

        return is_string($value) ? $value : '';
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
