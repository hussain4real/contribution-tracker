<?php

declare(strict_types=1);

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

it('aborts when a request user is unavailable', function () {
    $controller = new class extends Controller
    {
        public function requestUser(Request $request): User
        {
            return $this->user($request);
        }
    };

    expect(fn () => $controller->requestUser(Request::create('/')))
        ->toThrow(HttpException::class);
});

it('aborts when the authenticated user is unavailable', function () {
    $controller = new class extends Controller
    {
        public function currentUser(): User
        {
            return $this->authUser();
        }
    };

    expect(fn () => $controller->currentUser())
        ->toThrow(HttpException::class);
});
