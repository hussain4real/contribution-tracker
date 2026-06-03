<?php

declare(strict_types=1);

use App\Models\User;
use Laravel\Passkeys\Actions\VerifyPasskey;
use Mockery\MockInterface;

test('has passkeys endpoint returns false when no two factor login is active', function () {
    $this->postJson(route('passkey.two-factor.has-passkeys'))
        ->assertOk()
        ->assertJsonPath('hasPasskeys', false);
});

test('has passkeys endpoint returns false when the challenged user has no passkeys', function () {
    $user = User::factory()->create();

    $this->withSession(['login.id' => $user->id])
        ->postJson(route('passkey.two-factor.has-passkeys'))
        ->assertOk()
        ->assertJsonPath('hasPasskeys', false);
});

test('has passkeys endpoint returns true when the challenged user has passkeys', function () {
    $user = User::factory()->create();
    createTestPasskeyFor($user);

    $this->withSession(['login.id' => $user->id])
        ->postJson(route('passkey.two-factor.has-passkeys'))
        ->assertOk()
        ->assertJsonPath('hasPasskeys', true);
});

test('two factor passkey options can be generated for the challenged user', function () {
    $user = User::factory()->create();

    createTestPasskeyFor($user);
    createTestPasskeyFor($user);

    $response = $this->withSession(['login.id' => $user->id])
        ->getJson(route('passkey.two-factor.options'));

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
        ->assertJsonCount(2, 'options.allowCredentials')
        ->assertSessionHas('passkey.verification_options');
});

test('two factor passkey options return not found without passkeys', function () {
    $user = User::factory()->create();

    $this->withSession(['login.id' => $user->id])
        ->postJson(route('passkey.two-factor.options'))
        ->assertNotFound();
});

test('two factor passkey verify requires credential data', function () {
    $user = User::factory()->create();

    $this->withSession(['login.id' => $user->id])
        ->postJson(route('passkey.two-factor.verify'), [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['credential']);
});

test('two factor passkey verify rejects an unknown credential', function () {
    $user = User::factory()->create();
    createTestPasskeyFor($user);

    $this->withSession(['login.id' => $user->id])
        ->getJson(route('passkey.two-factor.options'))
        ->assertOk();

    $this->postJson(route('passkey.two-factor.verify'), [
        'credential' => fakePasskeyAssertionPayload(),
    ])->assertUnprocessable();

    $this->assertGuest();
});

test('two factor passkey verify signs in the challenged user', function () {
    $user = User::factory()->create();
    $passkey = createTestPasskeyFor($user);

    $this->mock(VerifyPasskey::class, function (MockInterface $mock) use ($passkey) {
        $mock->shouldReceive('__invoke')->once()->andReturn($passkey);
    });

    $this->withSession([
        'login.id' => $user->id,
        'login.remember' => true,
    ])->getJson(route('passkey.two-factor.options'))
        ->assertOk();

    $response = $this->postJson(route('passkey.two-factor.verify'), [
        'credential' => fakePasskeyAssertionPayload(),
    ]);

    $response->assertOk()
        ->assertJsonPath('redirect', route('dashboard'))
        ->assertSessionMissing('login.id')
        ->assertSessionMissing('login.remember');

    $this->assertAuthenticatedAs($user);
    $response->assertCookie(auth()->guard('web')->getRecallerName());
});

test('two factor passkey verify requires an active login challenge', function () {
    $this->postJson(route('passkey.two-factor.verify'), [
        'credential' => fakePasskeyAssertionPayload(),
    ])->assertUnprocessable()
        ->assertJsonPath('message', 'No two-factor challenge is active.');
});
