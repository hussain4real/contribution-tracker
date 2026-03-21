<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\StorePasskeyRequest;
use App\Models\Passkey;
use App\Services\WebAuthnService;
use Illuminate\Http\JsonResponse;
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
                ->select('id', 'name', 'attachment_type', 'last_used_at', 'created_at')
                ->latest()
                ->get(),
        ]);
    }

    /**
     * Generate registration options for a new passkey.
     */
    public function createOptions(Request $request, WebAuthnService $webAuthn): JsonResponse
    {
        $options = $webAuthn->generateRegistrationOptions($request->user());

        return response()->json($options);
    }

    /**
     * Store a newly registered passkey.
     */
    public function store(StorePasskeyRequest $request, WebAuthnService $webAuthn): JsonResponse
    {
        $passkey = $webAuthn->verifyRegistration(
            $request->validated('credential'),
            $request->validated('name')
        );

        return response()->json([
            'message' => 'Passkey registered successfully.',
            'passkey' => [
                'id' => $passkey->id,
                'name' => $passkey->name,
                'created_at' => $passkey->created_at,
            ],
        ], 201);
    }

    /**
     * Remove a registered passkey.
     */
    public function destroy(Request $request, Passkey $passkey): JsonResponse
    {
        if ($passkey->user_id !== $request->user()->id) {
            abort(403);
        }

        $passkey->delete();

        return response()->json(['message' => 'Passkey removed successfully.']);
    }
}
