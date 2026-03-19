<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Family;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetFamilyContext
{
    /**
     * Resolve the authenticated user's family and bind it to the container.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->family_id) {
            $family = $user->family;

            app()->instance('current-family', $family);
            app()->instance(Family::class, $family);
        }

        return $next($request);
    }
}
