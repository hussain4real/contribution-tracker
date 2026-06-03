<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Models\Family;
use App\Models\FamilyInvitation;
use App\Models\User;

test('registration screen can be rendered', function () {
    $response = $this->get(route('register'));

    $response->assertOk();
});

test('new users can register', function () {
    $response = $this->post(route('register.store'), [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
        'family_name' => 'Test Family',
    ]);

    $this->assertAuthenticated();
    $response->assertRedirect(route('dashboard', absolute: false));
});

test('new users can register from a family invitation', function () {
    $family = Family::factory()->create();
    $inviter = User::factory()->admin()->create(['family_id' => $family->id]);
    $invitation = FamilyInvitation::factory()->create([
        'family_id' => $family->id,
        'email' => 'invited@example.com',
        'role' => Role::FinancialSecretary,
        'invited_by' => $inviter->id,
        'expires_at' => now()->addDays(7),
    ]);

    $response = $this->post(route('register.store'), [
        'name' => 'Invited User',
        'email' => 'invited@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
        'invitation_token' => $invitation->token,
    ]);

    $response->assertRedirect(route('dashboard', absolute: false));
    $this->assertAuthenticated();

    $user = User::query()->where('email', 'invited@example.com')->firstOrFail();

    expect($user->family_id)->toBe($family->id)
        ->and($user->role)->toBe(Role::FinancialSecretary)
        ->and($invitation->refresh()->accepted_at)->not->toBeNull();
});

test('new users registering from a whatsapp invitation inherit the whatsapp phone', function () {
    $family = Family::factory()->create();
    $inviter = User::factory()->admin()->create(['family_id' => $family->id]);
    $invitation = FamilyInvitation::factory()->viaWhatsApp('+2348012345678')->create([
        'family_id' => $family->id,
        'role' => Role::Member,
        'invited_by' => $inviter->id,
        'expires_at' => now()->addDays(7),
    ]);

    $response = $this->post(route('register.store'), [
        'name' => 'Invited User',
        'email' => 'invited@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
        'invitation_token' => $invitation->token,
    ]);

    $response->assertRedirect(route('dashboard', absolute: false));
    $this->assertAuthenticated();

    $user = User::query()->where('email', 'invited@example.com')->firstOrFail();

    expect($user->family_id)->toBe($family->id)
        ->and($user->role)->toBe(Role::Member)
        ->and($user->whatsapp_phone)->toBe('+2348012345678')
        ->and($user->whatsapp_verified_at)->toBeNull()
        ->and($invitation->refresh()->accepted_at)->not->toBeNull();
});
