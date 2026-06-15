<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class PlatformAdminController extends Controller
{
    public function stopImpersonating(Request $request): RedirectResponse
    {
        $originalUserId = $request->session()->pull('impersonating_from');

        if (! $originalUserId) {
            return redirect()->route('dashboard');
        }

        $originalUser = User::query()
            ->whereKey($originalUserId)
            ->firstOrFail();

        auth()->login($originalUser);

        return redirect('/platform')->with('success', 'Stopped impersonating.');
    }
}
