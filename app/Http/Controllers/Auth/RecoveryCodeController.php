<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Http\Controllers\RecoveryCodeController as FortifyRecoveryCodeController;

class RecoveryCodeController extends FortifyRecoveryCodeController
{
    /**
     * @return JsonResponse|array<mixed>
     */
    public function index(Request $request): JsonResponse|array
    {
        try {
            return parent::index($request);
        } catch (DecryptException) {
            throw ValidationException::withMessages([
                'recovery_codes' => __('Your recovery codes could not be read. Contact support to restore access, or disable and re-enable two-factor authentication here to create a new set. Keep this session open until setup is complete.'),
            ]);
        }
    }
}
