<?php

declare(strict_types=1);

use App\Models\Family;
use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Fortify\Features;

test('login screen can be rendered', function () {
    $response = $this->get(route('login'));

    $response->assertStatus(200);
});

test('users can authenticate using the login screen', function () {
    $user = User::factory()->withoutTwoFactor()->create();

    $response = $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $this->assertAuthenticated();
    $response->assertRedirect(route('dashboard', absolute: false));
});

test('family roles are redirected to their current family dashboard after login', function (string $role) {
    $family = Family::factory()->create();
    $factory = match ($role) {
        'admin' => User::factory()->admin(),
        'financial-secretary' => User::factory()->financialSecretary(),
        'member' => User::factory()->member(),
        default => throw new InvalidArgumentException("Unsupported role [{$role}]."),
    };
    $user = $factory->withoutTwoFactor()->create(['family_id' => $family->id]);

    $response = $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $this->assertAuthenticatedAs($user);
    $response->assertRedirect(route('dashboard', ['current_family' => $family->slug], absolute: false));
})->with(['admin', 'financial-secretary', 'member']);

test('platform administrators are redirected to the platform dashboard after login', function () {
    $platformAdmin = User::factory()->superAdmin()->withoutTwoFactor()->create([
        'family_id' => null,
        'current_family_id' => null,
    ]);

    $response = $this->withSession(['url.intended' => '/dashboard'])
        ->post(route('login.store'), [
            'email' => $platformAdmin->email,
            'password' => 'password',
        ]);

    $this->assertAuthenticatedAs($platformAdmin);
    $response->assertRedirect(route('filament.platform.pages.dashboard', absolute: false));

    $this->get(route('filament.platform.pages.dashboard'))->assertOk();
});

test('Inertia login performs a full page visit to the platform dashboard', function () {
    $platformAdmin = User::factory()->superAdmin()->withoutTwoFactor()->create([
        'family_id' => null,
        'current_family_id' => null,
    ]);

    $this->withHeader('X-Inertia', 'true')
        ->post(route('login.store'), [
            'email' => $platformAdmin->email,
            'password' => 'password',
        ])
        ->assertStatus(409)
        ->assertHeader('X-Inertia-Location', route('filament.platform.pages.dashboard', absolute: false));

    $this->assertAuthenticatedAs($platformAdmin);
});

test('authenticated platform administrators visiting guest routes are redirected to the platform dashboard', function () {
    $platformAdmin = User::factory()->superAdmin()->withoutTwoFactor()->create([
        'family_id' => null,
        'current_family_id' => null,
    ]);

    $this->actingAs($platformAdmin)
        ->get(route('login'))
        ->assertRedirect(route('filament.platform.pages.dashboard', absolute: false));
});

test('users with two factor enabled are redirected to two factor challenge', function () {
    if (! Features::canManageTwoFactorAuthentication()) {
        $this->markTestSkipped('Two-factor authentication is not enabled.');
    }

    Features::twoFactorAuthentication([
        'confirm' => true,
        'confirmPassword' => true,
    ]);

    $user = User::factory()->create();

    $user->forceFill([
        'two_factor_secret' => encrypt('test-secret'),
        'two_factor_recovery_codes' => encrypt(json_encode(['code1', 'code2'])),
        'two_factor_confirmed_at' => now(),
    ])->save();

    $response = $this->post(route('login'), [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $response->assertRedirect(route('two-factor.login'));
    $response->assertSessionHas('login.id', $user->id);
    $this->assertGuest();
});

test('users can not authenticate with invalid password', function () {
    $user = User::factory()->create();

    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'wrong-password',
    ]);

    $this->assertGuest();
});

test('users can logout', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post(route('logout'));

    $this->assertGuest();
    $response->assertRedirect(route('home'));
});

test('users are rate limited', function () {
    $user = User::factory()->create();

    RateLimiter::increment(md5('login'.implode('|', [$user->email, '127.0.0.1'])), amount: 5);

    $response = $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'wrong-password',
    ]);

    $response->assertTooManyRequests();
});
