<?php

declare(strict_types=1);

use App\Models\User;
use Laravel\Passkeys\Actions\VerifyPasskey;
use Laravel\Passkeys\Passkeys;
use Mockery\MockInterface;

test('official passkey login options can be generated', function () {
    $response = $this->getJson(route('passkey.login-options'));

    $response->assertOk()
        ->assertJsonStructure([
            'options' => [
                'challenge',
                'rpId',
                'timeout',
                'userVerification',
                'allowCredentials',
            ],
        ])
        ->assertJsonPath('options.allowCredentials', []);
});

test('official passkey login requires credential data', function () {
    $this->postJson(route('passkey.login'), [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['credential']);
});

test('official passkey login rejects malformed credential data', function () {
    $this->getJson(route('passkey.login-options'))->assertOk();

    $this->postJson(route('passkey.login'), [
        'credential' => [
            'id' => 'invalid',
            'rawId' => 'invalid',
            'response' => [],
            'type' => 'public-key',
        ],
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['credential.response']);
});

test('official passkey login succeeds with a verified passkey', function () {
    $user = User::factory()->withoutTwoFactor()->create();
    $passkey = createTestPasskeyFor($user);

    $this->mock(VerifyPasskey::class, function (MockInterface $mock) use ($passkey) {
        $mock->shouldReceive('__invoke')->once()->andReturn($passkey);
    });

    $this->getJson(route('passkey.login-options'))->assertOk();

    $response = $this->postJson(route('passkey.login'), [
        'credential' => fakePasskeyAssertionPayload(),
        'remember' => true,
    ]);

    $response->assertOk()
        ->assertJsonPath('redirect', route('dashboard', absolute: false));

    $this->assertAuthenticatedAs($user);
    $response->assertCookie(auth()->guard('web')->getRecallerName());
});

test('official passkey login rejects archived users', function () {
    $user = User::factory()->withoutTwoFactor()->state([
        'archived_at' => now(),
    ])->create();
    $passkey = createTestPasskeyFor($user);

    $this->mock(VerifyPasskey::class, function (MockInterface $mock) use ($passkey) {
        $mock->shouldReceive('__invoke')->once()->andReturn($passkey);
    });

    $this->getJson(route('passkey.login-options'))->assertOk();

    $this->postJson(route('passkey.login'), [
        'credential' => fakePasskeyAssertionPayload(),
    ])->assertUnprocessable()
        ->assertJsonPath('message', 'Unable to sign in with this account.');

    $this->assertGuest();
    expect(Passkeys::allowsLogin(request(), $passkey))->toBeFalse();
});

test('official passkey login routes are only available to guests', function () {
    $user = User::factory()->withoutTwoFactor()->create();

    $this->actingAs($user)
        ->get(route('passkey.login-options'))
        ->assertRedirect(route('dashboard'));
});
