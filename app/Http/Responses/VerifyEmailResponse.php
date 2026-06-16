<?php

declare(strict_types=1);

namespace App\Http\Responses;

use App\Http\Responses\Concerns\RedirectsToCurrentFamily;
use Illuminate\Http\JsonResponse;
use Laravel\Fortify\Contracts\VerifyEmailResponse as VerifyEmailResponseContract;
use Laravel\Fortify\Fortify;
use Symfony\Component\HttpFoundation\Response;

class VerifyEmailResponse implements VerifyEmailResponseContract
{
    use RedirectsToCurrentFamily;

    public function toResponse($request): Response
    {
        return $request->wantsJson()
            ? new JsonResponse('', 204)
            : redirect()->intended($this->redirectPathForCurrentFamily($request, Fortify::redirects('email-verification')).'?verified=1');
    }
}
