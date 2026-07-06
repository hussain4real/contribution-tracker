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

test('welcome page focuses on contribution status and trust proof', function () {
    $response = $this->get(route('home'));
    $welcomePage = file_get_contents(resource_path('js/pages/Welcome.vue'));

    $response->assertSuccessful();

    if (! is_string($welcomePage)) {
        throw new RuntimeException('Expected Welcome.vue to be readable.');
    }

    expect($welcomePage)
        ->toContain('See who paid and who still owes')
        ->toContain('Payment history')
        ->toContain('Privacy by role')
        ->not->toContain('Everything you need to manage contributions')
        ->not->toContain('Trusted by families everywhere');
});

test('welcome page loads successfully for authenticated users', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get(route('home'));

    $response->assertSuccessful();
    $response->assertInertia(fn (Assert $page) => $page->component('Welcome'));
});
