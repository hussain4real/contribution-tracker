<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePasswordIsNotTemporary
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || ! $user->mustChangePassword() || $this->isAllowedRoute($request)) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'You must change your temporary password before continuing.',
            ], 423);
        }

        return redirect()
            ->route('user-password.edit')
            ->with('warning', 'Please change your temporary password before continuing.');
    }

    private function isAllowedRoute(Request $request): bool
    {
        return $request->routeIs(
            'user-password.edit',
            'user-password.update',
            'logout',
            'password.confirm',
            'password.confirmation',
        );
    }
}
