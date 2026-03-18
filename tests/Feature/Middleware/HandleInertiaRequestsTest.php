<?php

use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Http\Request;

test('share returns flash messages when session is available', function () {
    $this->get(route('home'))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page->has('flash'));
});

test('share returns flash values from session', function () {
    $response = $this->get(route('home'));

    $response->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->where('flash.success', null)
            ->where('flash.error', null)
            ->where('flash.warning', null)
        );
});

test('flash returns null when session is not available', function () {
    $middleware = new HandleInertiaRequests;

    $request = Request::create('/test', 'GET');
    // Do not set a session on the request

    $shared = $middleware->share($request);

    // Resolve the flash closures — should not throw
    expect($shared['flash']['success']())->toBeNull();
    expect($shared['flash']['error']())->toBeNull();
    expect($shared['flash']['warning']())->toBeNull();
});
