<?php

declare(strict_types=1);

namespace App\Http\Responses;

use App\Http\Responses\Concerns\RedirectsToCurrentFamily;
use Illuminate\Contracts\Support\Responsable;
use Laravel\Fortify\Fortify;
use Symfony\Component\HttpFoundation\Response;

class RedirectAsIntendedToCurrentFamily implements Responsable
{
    use RedirectsToCurrentFamily;

    public function __construct(public string $name) {}

    public function toResponse($request): Response
    {
        return redirect()->intended($this->redirectPathForCurrentFamily($request, Fortify::redirects($this->name)));
    }
}
