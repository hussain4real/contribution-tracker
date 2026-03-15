<?php

use App\Models\User;

test('guests are redirected away from dashboard', function () {
    $response = $this->get(route('dashboard'));
    $response->assertRedirect();
});

test('authenticated users can visit the dashboard', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $response = $this->get(route('dashboard'));
    $response->assertStatus(200);
});
