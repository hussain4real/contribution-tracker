<?php

declare(strict_types=1);

namespace App\Http\Responses\Concerns;

use App\Models\Family;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;

trait RedirectsToCurrentFamily
{
    protected function redirectPathForCurrentFamily(Request $request, string $redirect): string
    {
        $family = $this->currentFamily($request);

        URL::defaults(['current_family' => $family->slug]);

        return '/'.$family->slug.$redirect;
    }

    protected function currentFamily(Request $request): Family
    {
        $user = $request->user();

        abort_if(! $user instanceof User, 403);

        $family = $user->currentFamily ?? $user->family ?? $user->families()->orderByRaw('LOWER(families.name)')->first();

        abort_if(! $family instanceof Family, 403);

        if ($user->current_family_id !== $family->id || $user->family_id !== $family->id) {
            $user->switchFamily($family);
        }

        return $family;
    }
}
