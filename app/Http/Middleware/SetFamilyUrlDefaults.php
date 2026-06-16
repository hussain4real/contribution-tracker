<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

class SetFamilyUrlDefaults
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $routeFamily = $request->route('current_family') ?? $request->route('family');

        if (is_string($routeFamily)) {
            URL::defaults([
                'current_family' => $routeFamily,
                'family' => $routeFamily,
            ]);

            return $next($request);
        }

        $user = $request->user();

        if ($user instanceof User) {
            $family = $user->currentFamily ?? $user->family;

            if ($family) {
                URL::defaults([
                    'current_family' => $family->slug,
                    'family' => $family->slug,
                ]);
            }
        }

        return $next($request);
    }
}
