<?php

declare(strict_types=1);

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Passkeys\Actions\VerifyPasskey;
use Mockery\MockInterface;

test('passkeys settings page can be rendered with a fresh empty list', function () {
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

test('passkeys settings page lists official passkeys', function () {
    $user = User::factory()->withoutTwoFactor()->create();

    createTestPasskeyFor($user, ['name' => 'Work laptop']);
    createTestPasskeyFor($user, ['name' => 'Phone']);
    createTestPasskeyFor($user, ['name' => 'Security key']);

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->get(route('passkeys.show'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/Passkeys')
            ->has('passkeys', 3)
        );
});

test('official registration options can be generated after password confirmation', function () {
    $user = User::factory()->withoutTwoFactor()->create();

    $response = $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->getJson(route('passkey.registration-options'));

    $response->assertOk()
        ->assertJsonStructure([
            'options' => [
                'challenge',
                'rp' => ['name', 'id'],
                'user' => ['id', 'name', 'displayName'],
                'pubKeyCredParams',
                'authenticatorSelection',
                'timeout',
                'attestation',
            ],
        ])
        ->assertJsonPath('options.user.name', $user->email)
        ->assertJsonPath('options.user.displayName', $user->name);
});

test('official registration options exclude existing passkeys', function () {
    $user = User::factory()->withoutTwoFactor()->create();

    createTestPasskeyFor($user);
    createTestPasskeyFor($user);

    $response = $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->getJson(route('passkey.registration-options'));

    $response->assertOk()
        ->assertJsonCount(2, 'options.excludeCredentials');
});

test('official passkey registration validates credential data', function () {
    $user = User::factory()->withoutTwoFactor()->create();

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->postJson(route('passkey.store'), [
            'name' => 'MacBook Pro',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['credential']);
});

test('official passkey management routes require password confirmation', function () {
    $user = User::factory()->withoutTwoFactor()->create();
    $passkey = createTestPasskeyFor($user);

    $this->actingAs($user)
        ->get(route('passkey.registration-options'))
        ->assertRedirect(route('password.confirm'));

    $this->actingAs($user)
        ->delete(route('passkey.destroy', $passkey))
        ->assertRedirect(route('password.confirm'));
});

test('official passkey confirmation can satisfy password confirmation', function () {
    $user = User::factory()->withoutTwoFactor()->create();
    $passkey = createTestPasskeyFor($user);

    $this->mock(VerifyPasskey::class, function (MockInterface $mock) use ($passkey) {
        $mock->shouldReceive('__invoke')->once()->andReturn($passkey);
    });

    $this->actingAs($user)
        ->getJson(route('passkey.confirm-options'))
        ->assertOk()
        ->assertJsonCount(1, 'options.allowCredentials')
        ->assertSessionHas('passkey.verification_options');

    $this->postJson(route('passkey.confirm'), [
        'credential' => fakePasskeyAssertionPayload(),
    ])->assertOk()
        ->assertSessionHas('auth.password_confirmed_at');
});

test('a passkey can be deleted through the official endpoint', function () {
    $user = User::factory()->withoutTwoFactor()->create();
    $passkey = createTestPasskeyFor($user);

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->deleteJson(route('passkey.destroy', $passkey))
        ->assertOk()
        ->assertJsonPath('status', 'passkey-deleted');

    $this->assertDatabaseMissing('passkeys', ['id' => $passkey->id]);
});

test('a user cannot delete another users passkey', function () {
    $user = User::factory()->withoutTwoFactor()->create();
    $otherUser = User::factory()->withoutTwoFactor()->create();
    $passkey = createTestPasskeyFor($otherUser);

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->deleteJson(route('passkey.destroy', $passkey))
        ->assertForbidden();

    $this->assertDatabaseHas('passkeys', ['id' => $passkey->id]);
});

test('passkey routes require authentication', function () {
    $passkey = createTestPasskeyFor(User::factory()->withoutTwoFactor()->create());

    $this->get(route('passkeys.show'))->assertRedirect();
    $this->get(route('passkey.registration-options'))->assertRedirect(route('login'));
    $this->post(route('passkey.store'))->assertRedirect(route('login'));
    $this->delete(route('passkey.destroy', $passkey))->assertRedirect(route('login'));
});
