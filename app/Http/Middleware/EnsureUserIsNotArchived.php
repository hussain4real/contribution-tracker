<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsNotArchived
{
    /**
     * Handle an incoming request.
     *
     * Archived users should not be able to access the application.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (Auth::check() && Auth::user()->isArchived()) {
            Auth::logout();
            abort(403, 'Your account has been archived.');
        }

        return $next($request);
    }
}
