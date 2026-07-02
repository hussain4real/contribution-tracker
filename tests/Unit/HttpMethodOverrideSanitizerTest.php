<?php

use App\Support\HttpMethodOverrideSanitizer;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Exception\SuspiciousOperationException;

afterEach(function () {
    $_GET = [];
    $_POST = [];
    $_REQUEST = [];
    $_SERVER = [];
});

it('removes malformed form method overrides before the request is captured', function () {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST['_method'] = 'PUT /index.php?s=captcha';
    $_REQUEST['_method'] = $_POST['_method'];

    expect(fn () => Request::capture()->getMethod())
        ->toThrow(SuspiciousOperationException::class);

    $_POST['_method'] = 'PUT /index.php?s=captcha';
    $_REQUEST['_method'] = $_POST['_method'];

    HttpMethodOverrideSanitizer::sanitizeGlobals();

    expect($_POST)->not->toHaveKey('_method')
        ->and($_REQUEST)->not->toHaveKey('_method')
        ->and(Request::capture()->getMethod())->toBe('POST');
});

it('preserves valid form method overrides', function () {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST['_method'] = 'PATCH';
    $_REQUEST['_method'] = $_POST['_method'];

    HttpMethodOverrideSanitizer::sanitizeGlobals();

    expect($_POST['_method'])->toBe('PATCH')
        ->and(Request::capture()->getMethod())->toBe('PATCH');
});

it('removes malformed header method overrides before the request is captured', function () {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE'] = 'PATCH /admin';

    expect(fn () => Request::capture()->getMethod())
        ->toThrow(SuspiciousOperationException::class);

    $_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE'] = 'PATCH /admin';

    HttpMethodOverrideSanitizer::sanitizeGlobals();

    expect($_SERVER)->not->toHaveKey('HTTP_X_HTTP_METHOD_OVERRIDE')
        ->and(Request::capture()->getMethod())->toBe('POST');
});
