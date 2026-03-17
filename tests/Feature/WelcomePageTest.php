<?php

use App\Models\User;

test('welcome page loads successfully for guests', function () {
    $response = $this->get(route('home'));

    $response->assertSuccessful();
    $response->assertInertia(fn ($page) => $page
        ->component('Welcome')
        ->has('canRegister')
    );
});

test('welcome page displays correct app title', function () {
    $response = $this->get(route('home'));

    $response->assertSuccessful();
    $response->assertSee('FamilyFund');
});

test('welcome page loads successfully for authenticated users', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get(route('home'));

    $response->assertSuccessful();
    $response->assertInertia(fn ($page) => $page->component('Welcome'));
});
