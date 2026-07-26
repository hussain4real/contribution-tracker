<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Family;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureFamilyMembership
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $family = $this->family($request);
        $membership = $user instanceof User && $family instanceof Family
            ? $user->membershipForFamily($family)
            : null;

        abort_if(! $user instanceof User || ! $family instanceof Family || $membership === null, 403);

        if ($user->current_family_id !== $family->id || $user->family_id !== $family->id) {
            $user->switchFamily($family, $membership);
        }

        app()->instance('current-family', $family);
        app()->instance(Family::class, $family);

        $request->route()?->forgetParameter('current_family');

        return $next($request);
    }

    private function family(Request $request): ?Family
    {
        $family = $request->route('current_family') ?? $request->route('family');

        if ($family instanceof Family) {
            return $family;
        }

        if (app()->bound('current-family')) {
            $currentFamily = app('current-family');

            if ($currentFamily instanceof Family && $currentFamily->slug === $family) {
                return $currentFamily;
            }
        }

        if (is_string($family)) {
            return Family::query()->where('slug', $family)->first();
        }

        return null;
    }
}
