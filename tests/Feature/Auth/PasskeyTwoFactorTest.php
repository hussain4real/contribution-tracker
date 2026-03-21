<?php

use App\Models\Passkey;
use App\Models\User;

test('two factor passkey challenge options can be generated for users with passkeys', function () {
    $user = User::factory()->create();
    Passkey::factory()->for($user)->create();

    $response = $this->actingAs($user)
        ->postJson(route('passkey.two-factor.options'));

    $response->assertOk()
        ->assertJsonStructure([
            'challenge',
            'rpId',
            'timeout',
            'userVerification',
            'allowCredentials',
        ]);
});

test('two factor passkey challenge options includes user credentials', function () {
    $user = User::factory()->create();
    Passkey::factory()->count(2)->for($user)->create();

    $response = $this->actingAs($user)
        ->postJson(route('passkey.two-factor.options'));

    $response->assertOk()
        ->assertJsonCount(2, 'allowCredentials');
});

test('two factor passkey challenge returns not found for users without passkeys', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->postJson(route('passkey.two-factor.options'));

    $response->assertNotFound();
});

test('two factor passkey verify fails with invalid assertion', function () {
    $user = User::factory()->create();
    Passkey::factory()->for($user)->create();

    $this->actingAs($user)
        ->postJson(route('passkey.two-factor.options'));

    $response = $this->actingAs($user)
        ->postJson(route('passkey.two-factor.verify'), [
            'assertion' => [
                'id' => 'invalid',
                'rawId' => 'invalid',
                'response' => [
                    'clientDataJSON' => base64_encode('{}'),
                    'authenticatorData' => base64_encode('invalid'),
                    'signature' => base64_encode('invalid'),
                ],
                'type' => 'public-key',
            ],
        ]);

    $response->assertUnprocessable();
});

test('two factor passkey verify requires assertion data', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->postJson(route('passkey.two-factor.verify'), []);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['assertion']);
});

test('has passkeys endpoint returns false when user has no passkeys', function () {
    $response = $this->postJson(route('passkey.two-factor.has-passkeys'));

    $response->assertOk()
        ->assertJsonPath('hasPasskeys', false);
});

test('has passkeys endpoint returns true when user has passkeys', function () {
    $user = User::factory()->withoutTwoFactor()->create();
    Passkey::factory()->for($user)->create();

    $response = $this->withSession(['login.id' => $user->id])
        ->postJson(route('passkey.two-factor.has-passkeys'));

    $response->assertOk()
        ->assertJsonPath('hasPasskeys', true);
});

test('two factor passkey routes require a login session', function () {
    $this->postJson(route('passkey.two-factor.options'))->assertNotFound();
    $this->postJson(route('passkey.two-factor.verify'))->assertUnprocessable();
});
