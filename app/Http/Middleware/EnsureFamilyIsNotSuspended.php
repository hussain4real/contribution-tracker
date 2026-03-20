<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureFamilyIsNotSuspended
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // Super admins can always access the platform
        if ($user?->isSuperAdmin()) {
            return $next($request);
        }

        if ($user?->family?->isSuspended()) {
            Auth::logout();
            abort(403, 'Your family account has been suspended. Please contact support.');
        }

        return $next($request);
    }
}
