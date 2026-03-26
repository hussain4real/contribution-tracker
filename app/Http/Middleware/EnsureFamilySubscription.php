<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureFamilySubscription
{
    /**
     * Verify the authenticated user's family has an active subscription
     * or is on the free plan within limits.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, ?string $feature = null): Response
    {
        $user = $request->user();
        $family = $user?->family;

        // No family means user is setting up — let them through
        if (! $family) {
            return $next($request);
        }

        $plan = $family->platformPlan;

        // No plan assigned = free tier (default behavior)
        if (! $plan) {
            return $next($request);
        }

        // Check member limit on member-adding routes
        if (! $plan->hasUnlimitedMembers()) {
            if ($request->routeIs('members.store', 'members.create', 'invitations.store', 'family.invitations.store')) {
                $memberCount = $family->members()->count();

                if ($memberCount >= $plan->max_members) {
                    if ($request->expectsJson()) {
                        return response()->json([
                            'message' => "Your plan allows up to {$plan->max_members} members. Please upgrade to add more.",
                        ], 403);
                    }

                    return redirect()->route('subscription.index')
                        ->with('error', "Your plan allows up to {$plan->max_members} members. Please upgrade to add more.");
                }
            }
        }

        // Check feature access if a specific feature is requested
        if ($feature) {
            $features = $plan->features ?? [];

            if (! in_array($feature, $features, true)) {
                if ($request->expectsJson()) {
                    return response()->json([
                        'message' => 'This feature is not available on your current plan. Please upgrade.',
                    ], 403);
                }

                return redirect()->route('subscription.index')
                    ->with('error', 'This feature is not available on your current plan. Please upgrade.');
            }
        }

        // Check subscription status for paid plans
        if ($plan->isPaid()) {
            $status = $family->subscription_status;

            if (in_array($status, ['cancelled', 'past_due'], true)) {
                // Allow access to subscription page and settings so they can resubscribe
                if ($request->routeIs('subscription.*', 'family.settings', 'dashboard')) {
                    return $next($request);
                }

                if ($request->expectsJson()) {
                    return response()->json([
                        'message' => 'Your subscription is inactive. Please update your subscription.',
                    ], 403);
                }

                return redirect()->route('subscription.index')
                    ->with('error', 'Your subscription is inactive. Please renew to continue.');
            }
        }

        return $next($request);
    }
}
