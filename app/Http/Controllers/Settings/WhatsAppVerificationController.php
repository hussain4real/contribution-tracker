<?php

namespace App\Http\Controllers\Settings;

use App\Channels\WhatsAppMessage;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\SendWhatsAppCodeRequest;
use App\Http\Requests\Settings\VerifyWhatsAppCodeRequest;
use App\Services\WhatsAppService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Manages WhatsApp phone number verification for the authenticated user.
 *
 * Flow:
 * 1. POST /settings/whatsapp/send-code  — generates a 6-digit OTP, caches it
 *    for 10 minutes, sends it via the `verification_code` template.
 * 2. POST /settings/whatsapp/verify     — validates the OTP and marks the
 *    phone as verified (sets whatsapp_verified_at).
 * 3. DELETE /settings/whatsapp          — removes the phone and clears
 *    verification.
 */
class WhatsAppVerificationController extends Controller
{
    /**
     * Cache time-to-live for OTP codes (in seconds).
     */
    protected const OTP_TTL_SECONDS = 600;

    public function __construct(
        protected WhatsAppService $whatsapp,
    ) {}

    /**
     * Generate and send an OTP to the supplied WhatsApp number.
     */
    public function sendCode(SendWhatsAppCodeRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        $phone = $validated['whatsapp_phone'];
        $code = (string) random_int(100000, 999999);

        Cache::put($this->cacheKey($request->user()->id), [
            'code' => $code,
            'phone' => $phone,
        ], self::OTP_TTL_SECONDS);

        $message = (new WhatsAppMessage)
            ->template('verification_code', 'en')
            ->body([$code])
            ->button('url', '0', $code);

        $result = $this->whatsapp->send($this->whatsapp->normalisePhone($phone), $message);

        if (! $result['success']) {
            return back()->withErrors([
                'whatsapp_phone' => 'Could not send verification code. Please check the number and try again.',
            ])->withInput();
        }

        return back()->with('status', 'whatsapp-code-sent');
    }

    /**
     * Verify the OTP and mark the phone as verified.
     */
    public function verifyCode(VerifyWhatsAppCodeRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        $cached = Cache::get($this->cacheKey($request->user()->id));

        if (! is_array($cached) || ! hash_equals((string) $cached['code'], $validated['code'])) {
            return back()->withErrors([
                'code' => 'The verification code is invalid or has expired.',
            ]);
        }

        $request->user()->forceFill([
            'whatsapp_phone' => $cached['phone'],
            'whatsapp_verified_at' => now(),
        ])->save();

        Cache::forget($this->cacheKey($request->user()->id));

        return back()->with('status', 'whatsapp-verified');
    }

    /**
     * Remove the user's WhatsApp number.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $request->user()->forceFill([
            'whatsapp_phone' => null,
            'whatsapp_verified_at' => null,
        ])->save();

        Cache::forget($this->cacheKey($request->user()->id));

        return back()->with('status', 'whatsapp-removed');
    }

    protected function cacheKey(int $userId): string
    {
        return "whatsapp_otp:{$userId}";
    }
}
