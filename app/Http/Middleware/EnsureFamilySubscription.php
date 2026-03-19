<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureFamilySubscription
{
    /**
     * Handle an incoming request.
     *
     * Billing guard — currently a pass-through. When billing is enabled,
     * this will verify the family's plan is active / trial is not expired.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // TODO: Activate billing enforcement when plans are enabled.
        // $family = $request->user()?->family;
        // if ($family && $family->plan !== 'free' && $family->trial_ends_at?->isPast()) {
        //     return redirect()->route('family.settings')->with('error', 'Your subscription has expired.');
        // }

        return $next($request);
    }
}
