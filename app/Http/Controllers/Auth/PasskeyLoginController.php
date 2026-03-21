<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\WebAuthnService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class PasskeyLoginController extends Controller
{
    /**
     * Generate authentication options for passkey login.
     */
    public function challengeOptions(WebAuthnService $webAuthn): JsonResponse
    {
        $options = $webAuthn->generateAuthenticationOptions();

        return response()->json($options);
    }

    /**
     * Authenticate a user using a passkey assertion.
     */
    public function login(Request $request, WebAuthnService $webAuthn): JsonResponse
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
            Log::error('Passkey authentication failed', [
                'error' => $e->getMessage(),
                'assertion_id' => $request->input('assertion.id'),
            ]);

            return response()->json(['message' => 'Authentication failed.'], 422);
        }

        if ($user->isArchived()) {
            return response()->json(['message' => 'This account has been archived.'], 403);
        }

        Auth::login($user, remember: true);

        $request->session()->regenerate();

        if ($user->hasEnabledTwoFactorAuthentication()) {
            $request->session()->put('two_factor_confirmed_via_passkey', true);
        }

        return response()->json([
            'redirect' => route('dashboard'),
        ]);
    }
}
