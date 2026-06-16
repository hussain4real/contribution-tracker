<?php

declare(strict_types=1);

namespace App\Http\Responses;

use App\Http\Responses\Concerns\RedirectsToCurrentFamily;
use Laravel\Fortify\Contracts\RegisterResponse as RegisterResponseContract;
use Laravel\Fortify\Fortify;
use Symfony\Component\HttpFoundation\Response;

class RegisterResponse implements RegisterResponseContract
{
    use RedirectsToCurrentFamily;

    public function toResponse($request): Response
    {
        return redirect()->intended($this->redirectPathForCurrentFamily($request, Fortify::redirects('register')));
    }
}
