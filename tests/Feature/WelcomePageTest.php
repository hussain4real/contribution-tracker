<?php

declare(strict_types=1);

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('welcome page loads successfully for guests', function () {
    $response = $this->get(route('home'));

    $response->assertSuccessful();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Welcome')
        ->has('canRegister')
    );
});

test('welcome page displays correct app title', function () {
    $response = $this->get(route('home'));
    $appName = config('app.name');

    if (! is_string($appName)) {
        throw new RuntimeException('Expected app name config to be a string.');
    }

    $response->assertSuccessful();
    $response->assertSee($appName);
});

test('welcome page loads successfully for authenticated users', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get(route('home'));

    $response->assertSuccessful();
    $response->assertInertia(fn (Assert $page) => $page->component('Welcome'));
});
