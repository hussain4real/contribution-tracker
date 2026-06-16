<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Family;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

class FamilySwitchController extends Controller
{
    public function __invoke(Request $request, Family $family): RedirectResponse
    {
        $user = $this->user($request);

        abort_unless($user->switchFamily($family), 403);

        Inertia::flash('toast', ['type' => 'success', 'message' => __("Switched to {$family->name}.")]);

        return to_route('dashboard');
    }
}
