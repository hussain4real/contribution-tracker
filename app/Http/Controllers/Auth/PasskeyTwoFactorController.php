<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Passkey;
use App\Services\WebAuthnService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PasskeyTwoFactorController extends Controller
{
    /**
     * Generate authentication options for 2FA passkey challenge.
     */
    public function challengeOptions(Request $request, WebAuthnService $webAuthn): JsonResponse
    {
        $user = $request->user();

        if (! $user || ! $user->passkeys()->exists()) {
            return response()->json(['message' => 'No passkeys registered.'], 404);
        }

        $options = $webAuthn->generateAuthenticationOptions($user);

        return response()->json($options);
    }

    /**
     * Verify a 2FA passkey assertion.
     */
    public function verify(Request $request, WebAuthnService $webAuthn): JsonResponse
    {
        $request->validate([
            'assertion' => ['required', 'array'],
            'assertion.id' => ['required', 'string'],
            'assertion.rawId' => ['required', 'string'],
            'assertion.response' => ['required', 'array'],
            'assertion.response.clientDataJSON' => ['required', 'string'],
            'assertion.response.authenticatorData' => ['required', 'string'],
            'assertion.response.signature' => ['required', 'string'],
            'assertion.type' => ['required', 'string', 'in:public-key'],
        ]);

        try {
            $user = $webAuthn->verifyAuthentication($request->input('assertion'));
        } catch (\RuntimeException $e) {
            return response()->json(['message' => 'Biometric verification failed.'], 422);
        }

        if ($user->id !== $request->user()->id) {
            return response()->json(['message' => 'Passkey does not belong to this account.'], 422);
        }

        $request->session()->put('two_factor_confirmed_via_passkey', true);

        return response()->json([
            'redirect' => route('dashboard'),
        ]);
    }

    /**
     * Check if the current user has registered passkeys (for frontend conditional rendering).
     */
    public function hasPasskeys(Request $request): JsonResponse
    {
        $hasPasskeys = false;

        $userId = $request->session()->get('login.id');
        if ($userId) {
            $hasPasskeys = Passkey::where('user_id', $userId)->exists();
        }

        return response()->json(['hasPasskeys' => $hasPasskeys]);
    }
}
