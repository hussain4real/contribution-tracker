<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Family;
use App\Models\User;
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
        app()->forgetInstance('current-family');
        app()->forgetInstance(Family::class);

        $user = $request->user();

        if (! $user instanceof User) {
            return $next($request);
        }

        // Super admins can always access the platform
        if ($user->isSuperAdmin()) {
            return $next($request);
        }

        $family = $this->family($request) ?? $user->currentFamily ?? $user->family;

        if ($family?->isSuspended()) {
            Auth::logout();
            abort(403, 'Your family account has been suspended. Please contact support.');
        }

        if ($family instanceof Family) {
            app()->instance('current-family', $family);
            app()->instance(Family::class, $family);
        }

        return $next($request);
    }

    private function family(Request $request): ?Family
    {
        $family = $request->route('current_family');

        if ($family instanceof Family) {
            return $family;
        }

        if (is_string($family)) {
            return Family::query()->where('slug', $family)->first();
        }

        return null;
    }
}
