<?php

declare(strict_types=1);

use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\EnsureUserIsNotArchived;
use App\Http\Requests\StoreMemberRequest;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

it('preserves a valid incoming request identifier', function () {
    $requestId = (string) Str::uuid();
    $request = Request::create('/request-id-test', server: ['HTTP_X_REQUEST_ID' => $requestId]);

    $response = app(AssignRequestId::class)->handle($request, fn () => response('ok'));
    $containerRequestId = app('request-id');

    if (! is_string($containerRequestId)) {
        throw new UnexpectedValueException('The request identifier must be a string.');
    }

    expect($request->attributes->get('request_id'))->toBe($requestId);
    expect($response->headers->get('X-Request-ID'))->toBe($requestId);
    expect($containerRequestId)->toBe($requestId);
});

it('logs out a globally archived account before handling a protected request', function () {
    $user = User::factory()->archived()->create();
    $request = Request::create('/protected');
    $request->setUserResolver(fn (): User => $user);
    Auth::login($user);

    expect(fn () => app(EnsureUserIsNotArchived::class)->handle($request, fn () => response('ok')))
        ->toThrow(HttpException::class, 'Your account has been archived.');

    expect(Auth::check())->toBeFalse();
});

it('leaves a legacy category untouched when no family context exists', function () {
    $user = User::factory()->create([
        'family_id' => null,
        'current_family_id' => null,
    ]);
    $request = new class extends StoreMemberRequest
    {
        public function prepareForTest(): void
        {
            $this->prepareForValidation();
        }
    };
    $request->setUserResolver(fn (): User => $user);
    $request->merge(['category' => 'employed']);

    $request->prepareForTest();

    expect($request->input('family_category_id'))->toBeNull();
});
