<?php

declare(strict_types=1);

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Testing\AssertableInertia as Assert;

test('share returns flash messages when session is available', function () {
    $this->get(route('home'))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page->has('flash'));
});

test('share returns flash values from session', function () {
    $response = $this->get(route('home'));

    $response->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
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
    $flash = resultArray($shared, 'flash');
    $success = $flash['success'] ?? null;
    $error = $flash['error'] ?? null;
    $warning = $flash['warning'] ?? null;

    if (! is_callable($success) || ! is_callable($error) || ! is_callable($warning)) {
        throw new RuntimeException('Expected flash values to be closures.');
    }

    // Resolve the flash closures — should not throw
    expect($success())->toBeNull();
    expect($error())->toBeNull();
    expect($warning())->toBeNull();
});

test('subscription data falls back when a user has no family', function () {
    $middleware = new HandleInertiaRequests;
    $method = (new ReflectionClass($middleware))->getMethod('subscriptionData');
    $user = User::factory()->make(['family_id' => null]);

    expect($method->invoke($middleware, $user))->toBe([
        'plan_name' => null,
        'member_count' => 0,
        'max_members' => null,
        'can_add_members' => false,
        'features' => [],
    ]);
});
