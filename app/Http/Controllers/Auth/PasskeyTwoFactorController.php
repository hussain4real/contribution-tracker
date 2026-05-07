<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Passkeys\Actions\GenerateVerificationOptions;
use Laravel\Passkeys\Actions\VerifyPasskey;
use Laravel\Passkeys\Http\Requests\PasskeyVerificationRequest;
use Laravel\Passkeys\Support\WebAuthn;

class PasskeyTwoFactorController extends Controller
{
    /**
     * Generate authentication options for 2FA passkey challenge.
     */
    public function challengeOptions(Request $request, GenerateVerificationOptions $generate): JsonResponse
    {
        $user = $this->challengedUser($request);

        if (! $user?->hasPasskeysEnabled()) {
            return response()->json(['message' => 'No passkeys registered.'], 404);
        }

        $options = $generate($user);
        $serialized = WebAuthn::toJson($options);

        $request->session()->put('passkey.verification_options', $serialized);

        return response()->json([
            'options' => json_decode($serialized, true),
        ]);
    }

    /**
     * Verify a 2FA passkey assertion.
     */
    public function verify(
        PasskeyVerificationRequest $request,
        VerifyPasskey $verify,
        StatefulGuard $guard,
    ): JsonResponse {
        $user = $this->challengedUser($request);

        if (! $user) {
            return response()->json(['message' => 'No two-factor challenge is active.'], 422);
        }

        $verify(
            $request->credential(),
            $request->verificationOptions(),
            $user,
        );

        $remember = (bool) $request->session()->pull('login.remember', false);

        $request->session()->forget('login.id');
        $guard->login($user, $remember);
        $request->session()->regenerate();

        return response()->json([
            'redirect' => route('dashboard'),
        ]);
    }

    /**
     * Check if the challenged user has registered passkeys.
     */
    public function hasPasskeys(Request $request): JsonResponse
    {
        return response()->json([
            'hasPasskeys' => $this->challengedUser($request)?->hasPasskeysEnabled() ?? false,
        ]);
    }

    /**
     * Get the user Fortify is currently holding for two-factor challenge.
     */
    private function challengedUser(Request $request): ?User
    {
        $userId = $request->session()->get('login.id');

        if (! $userId) {
            return null;
        }

        return User::query()->find($userId);
    }
}
