<?php

declare(strict_types=1);

namespace App\Http\Responses;

use App\Http\Responses\Concerns\RedirectsToCurrentFamily;
use Illuminate\Http\JsonResponse;
use Laravel\Fortify\Contracts\TwoFactorLoginResponse as TwoFactorLoginResponseContract;
use Laravel\Fortify\Fortify;
use Symfony\Component\HttpFoundation\Response;

class TwoFactorLoginResponse implements TwoFactorLoginResponseContract
{
    use RedirectsToCurrentFamily;

    public function toResponse($request): Response
    {
        return $request->wantsJson()
            ? new JsonResponse('', 204)
            : redirect()->intended($this->redirectPathForCurrentFamily($request, Fortify::redirects('login')));
    }
}
