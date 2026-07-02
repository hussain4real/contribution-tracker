<?php

declare(strict_types=1);

use App\Http\HttpMethodOverrideSanitizer;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Exception\SuspiciousOperationException;

$originalGlobals = [];

beforeEach(function () use (&$originalGlobals) {
    $originalGlobals = [
        'get' => $_GET,
        'post' => $_POST,
        'request' => $_REQUEST,
        'server' => $_SERVER,
    ];
});

afterEach(function () use (&$originalGlobals) {
    $_GET = $originalGlobals['get'];
    $_POST = $originalGlobals['post'];
    $_REQUEST = $originalGlobals['request'];
    $_SERVER = $originalGlobals['server'];
});

it('removes unsupported form method overrides before the request is captured', function () {
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

it('removes alphabetic but unsupported form method overrides before the request is captured', function () {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST['_method'] = 'CAPTCHA';
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

it('removes unsupported header method overrides before the request is captured', function () {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE'] = 'PATCH /admin';

    expect(fn () => Request::capture()->getMethod())
        ->toThrow(SuspiciousOperationException::class);

    $_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE'] = 'PATCH /admin';

    HttpMethodOverrideSanitizer::sanitizeGlobals();

    expect($_SERVER)->not->toHaveKey('HTTP_X_HTTP_METHOD_OVERRIDE')
        ->and(Request::capture()->getMethod())->toBe('POST');
});
