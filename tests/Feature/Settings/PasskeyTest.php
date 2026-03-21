<?php

use App\Models\Passkey;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('passkeys settings page can be rendered', function () {
    $user = User::factory()->withoutTwoFactor()->create();

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->get(route('passkeys.show'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/Passkeys')
            ->has('passkeys', 0)
        );
});

test('passkeys settings page requires password confirmation', function () {
    $user = User::factory()->withoutTwoFactor()->create();

    $this->actingAs($user)
        ->get(route('passkeys.show'))
        ->assertRedirect(route('password.confirm'));
});

test('passkeys settings page lists registered passkeys', function () {
    $user = User::factory()->withoutTwoFactor()->create();
    Passkey::factory()->count(3)->for($user)->create();

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->get(route('passkeys.show'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/Passkeys')
            ->has('passkeys', 3)
        );
});

test('registration options can be generated', function () {
    $user = User::factory()->withoutTwoFactor()->create();

    $response = $this->actingAs($user)
        ->postJson(route('passkeys.create-options'));

    $response->assertOk()
        ->assertJsonStructure([
            'challenge',
            'rp' => ['name', 'id'],
            'user' => ['id', 'name', 'displayName'],
            'pubKeyCredParams',
            'authenticatorSelection',
            'timeout',
            'attestation',
        ]);
});

test('registration options include user details', function () {
    $user = User::factory()->withoutTwoFactor()->create();

    $response = $this->actingAs($user)
        ->postJson(route('passkeys.create-options'));

    $response->assertOk()
        ->assertJsonPath('user.name', $user->email)
        ->assertJsonPath('user.displayName', $user->name);
});

test('registration options exclude existing passkeys', function () {
    $user = User::factory()->withoutTwoFactor()->create();
    Passkey::factory()->count(2)->for($user)->create();

    $response = $this->actingAs($user)
        ->postJson(route('passkeys.create-options'));

    $response->assertOk()
        ->assertJsonCount(2, 'excludeCredentials');
});

test('a passkey can be deleted', function () {
    $user = User::factory()->withoutTwoFactor()->create();
    $passkey = Passkey::factory()->for($user)->create();

    $this->actingAs($user)
        ->deleteJson(route('passkeys.destroy', $passkey))
        ->assertOk();

    $this->assertDatabaseMissing('passkeys', ['id' => $passkey->id]);
});

test('a user cannot delete another users passkey', function () {
    $user = User::factory()->withoutTwoFactor()->create();
    $otherUser = User::factory()->withoutTwoFactor()->create();
    $passkey = Passkey::factory()->for($otherUser)->create();

    $this->actingAs($user)
        ->deleteJson(route('passkeys.destroy', $passkey))
        ->assertForbidden();

    $this->assertDatabaseHas('passkeys', ['id' => $passkey->id]);
});

test('passkey routes require authentication', function () {
    $this->get(route('passkeys.show'))->assertRedirect();
});
