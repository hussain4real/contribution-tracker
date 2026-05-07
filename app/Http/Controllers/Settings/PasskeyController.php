<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Inertia\Inertia;
use Inertia\Response;

class PasskeyController extends Controller implements HasMiddleware
{
    /**
     * Get the middleware that should be assigned to the controller.
     *
     * @return list<Middleware>
     */
    public static function middleware(): array
    {
        return [
            new Middleware('password.confirm', only: ['show']),
        ];
    }

    /**
     * Show the passkey management page.
     */
    public function show(Request $request): Response
    {
        return Inertia::render('settings/Passkeys', [
            'passkeys' => $request->user()->passkeys()
                ->select('id', 'name', 'credential', 'last_used_at', 'created_at')
                ->latest()
                ->get()
                ->map(fn ($passkey): array => [
                    'id' => $passkey->id,
                    'name' => $passkey->name,
                    'authenticator' => $passkey->authenticator,
                    'last_used_at' => $passkey->last_used_at,
                    'created_at' => $passkey->created_at,
                ]),
        ]);
    }
}
