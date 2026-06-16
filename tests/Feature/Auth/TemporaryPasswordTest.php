<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Hash;

it('redirects temporary password users to update their password', function () {
    $user = User::factory()->create([
        'must_change_password_at' => now(),
    ]);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertRedirect(route('security.edit'))
        ->assertSessionHas('warning', 'Please change your temporary password before continuing.');
});

it('allows temporary password users to view the password update page', function () {
    $user = User::factory()->create([
        'must_change_password_at' => now(),
    ]);

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->get(route('security.edit'))
        ->assertOk();
});

it('clears temporary password enforcement after password update', function () {
    $user = User::factory()->create([
        'must_change_password_at' => now(),
    ]);

    $this->actingAs($user)
        ->from(route('security.edit'))
        ->put(route('user-password.update'), [
            'current_password' => 'password',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('security.edit'));

    $user->refresh();

    expect($user->must_change_password_at)->toBeNull()
        ->and(Hash::check('new-password', $user->password))->toBeTrue();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk();
});
