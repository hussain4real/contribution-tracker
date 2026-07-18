<?php

declare(strict_types=1);

namespace App\Http\Responses\Concerns;

use App\Models\Family;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

trait RedirectsToCurrentFamily
{
    protected function redirectPathForCurrentFamily(Request $request, string $redirect): string
    {
        $user = $this->authenticatedUser($request);

        if ($user->isSuperAdmin()) {
            return route('filament.platform.pages.dashboard', absolute: false);
        }

        $family = $this->currentFamily($user);

        URL::defaults(['current_family' => $family->slug]);

        return '/'.$family->slug.$redirect;
    }

    protected function redirectToIntendedOrDefault(Request $request, string $redirect): Response
    {
        if ($this->authenticatedUser($request)->isSuperAdmin()) {
            return Inertia::location($redirect);
        }

        return redirect()->intended($redirect);
    }

    protected function currentFamily(User $user): Family
    {
        $family = $user->currentFamily ?? $user->family ?? $user->families()->orderByRaw('LOWER(families.name)')->first();

        abort_if(! $family instanceof Family, 403);

        if ($user->current_family_id !== $family->id || $user->family_id !== $family->id) {
            $user->switchFamily($family);
        }

        return $family;
    }

    protected function authenticatedUser(Request $request): User
    {
        $user = $request->user();

        abort_if(! $user instanceof User, 403);

        return $user;
    }
}
