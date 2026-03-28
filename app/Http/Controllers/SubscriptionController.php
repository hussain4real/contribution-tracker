<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\SubscribeFamilyToPlan;
use App\Enums\TransactionStatus;
use App\Http\Requests\SubscribePlanRequest;
use App\Models\Family;
use App\Models\PaystackTransaction;
use App\Models\PlatformPlan;
use App\Models\User;
use App\Services\PaystackService;
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
        /** @var User $user */
        $user = auth()->user();
        $family = $user->family;

        $plans = PlatformPlan::where('is_active', true)
            ->orderBy('sort_order')
            ->get()
            ->map(fn (PlatformPlan $plan) => [
                'id' => $plan->id,
                'name' => $plan->name,
                'slug' => $plan->slug,
                'price' => $plan->price,
                'formatted_price' => $plan->formattedPrice(),
                'max_members' => $plan->max_members,
                'features' => $plan->features ?? [],
                'is_current' => $family?->platform_plan_id === $plan->id,
            ]);

        return Inertia::render('Subscription/Index', [
            'plans' => $plans,
            'current_plan' => $family?->platformPlan?->only(['id', 'name', 'slug', 'price']),
            'subscription_status' => $family?->subscription_status ?? 'free',
            'current_period_end' => $family?->current_period_end?->toDateString(),
            'member_count' => $family?->members()->count() ?? 0,
            'is_admin' => $user->isAdmin(),
            'paystack_public_key' => config('services.paystack.public_key'),
        ]);
    }

    /**
     * Subscribe the family to a paid plan via Paystack.
     */
    public function subscribe(SubscribePlanRequest $request, SubscribeFamilyToPlan $action): JsonResponse
    {
        $validated = $request->validated();

        /** @var User $user */
        $user = auth()->user();
        $family = $user->family;

        if (! $family) {
            return response()->json([
                'message' => 'You must belong to a family to subscribe.',
            ], 422);
        }

        $plan = PlatformPlan::findOrFail($validated['plan_id']);

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

        if (! $reference) {
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

            if ($verification['data']['status'] === 'success') {
                // Atomic conditional update to prevent race condition with webhook
                $updated = PaystackTransaction::where('reference', $reference)
                    ->where('status', TransactionStatus::Pending)
                    ->update([
                        'status' => TransactionStatus::Success,
                        'paystack_response' => json_encode($verification['data']),
                    ]);

                if ($updated === 0) {
                    // Already processed by webhook — redirect gracefully
                    return redirect()->route('subscription.index')
                        ->with('success', 'Subscription is already active.');
                }

                // Update family subscription
                $family = Family::find($transaction->family_id);
                $planId = $transaction->metadata['plan_id'] ?? null;

                if ($family && $planId) {
                    $paidAt = $verification['data']['paid_at'] ?? null;

                    $family->update([
                        'platform_plan_id' => $planId,
                        'subscription_status' => 'active',
                        'current_period_end' => $paidAt ? now()->parse($paidAt)->addMonth() : null,
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
        /** @var User $user */
        $user = auth()->user();

        if (! $user->isAdmin()) {
            abort(403, 'Only family admins can manage subscriptions.');
        }

        $family = $user->family;

        if (! $family?->paystack_subscription_code) {
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
}
