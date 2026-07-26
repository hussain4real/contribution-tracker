<?php

declare(strict_types=1);

use App\Enums\MemberCategory;
use App\Enums\Role;
use App\Models\Family;
use App\Models\FamilyCategory;
use App\Models\FamilyInvitation;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('legacy user family fields create a current family membership', function () {
    $family = Family::factory()->create();

    $user = User::factory()->financialSecretary()->create([
        'family_id' => $family->id,
    ]);

    $membership = $user->familyMemberships()->first();

    expect($user->current_family_id)->toBe($family->id)
        ->and($membership?->family_id)->toBe($family->id)
        ->and($membership?->role)->toBe(Role::FinancialSecretary);
});

test('family slug routes require membership and switch current family context', function () {
    $primaryFamily = Family::factory()->create(['name' => 'Primary Family']);
    $secondaryFamily = Family::factory()->create(['name' => 'Secondary Family']);
    $outsideFamily = Family::factory()->create(['name' => 'Outside Family']);
    $user = User::factory()->admin()->create(['family_id' => $primaryFamily->id]);

    $user->ensureFamilyMembership($secondaryFamily, Role::FinancialSecretary);

    $this->actingAs($user)
        ->get("/{$primaryFamily->slug}/dashboard")
        ->assertOk();

    expect($user->refresh()->current_family_id)->toBe($primaryFamily->id);

    $this->get("/{$secondaryFamily->slug}/dashboard")
        ->assertOk();

    expect($user->refresh()->current_family_id)->toBe($secondaryFamily->id)
        ->and($user->role)->toBe(Role::FinancialSecretary);

    $this->get("/{$outsideFamily->slug}/dashboard")
        ->assertForbidden();
});

test('family switching updates url defaults and legacy family mirrors', function () {
    $primaryFamily = Family::factory()->create(['name' => 'Primary Family']);
    $secondaryFamily = Family::factory()->create(['name' => 'Secondary Family']);
    $user = User::factory()->admin()->create(['family_id' => $primaryFamily->id]);

    $user->ensureFamilyMembership($secondaryFamily, Role::Member);

    $this->actingAs($user);

    expect(route('dashboard', absolute: false))->toBe("/{$primaryFamily->slug}/dashboard");

    $this->post(route('families.switch', $secondaryFamily))
        ->assertRedirect("/{$secondaryFamily->slug}/dashboard");

    $user->refresh();

    expect($user->current_family_id)->toBe($secondaryFamily->id)
        ->and($user->family_id)->toBe($secondaryFamily->id)
        ->and($user->role)->toBe(Role::Member)
        ->and(route('dashboard', absolute: false))->toBe("/{$secondaryFamily->slug}/dashboard");
});

test('legacy dashboard path redirects installed pwa launches to the current family dashboard', function () {
    $family = Family::factory()->create(['name' => 'PWA Launch Family']);
    $user = User::factory()->admin()->create(['family_id' => $family->id]);

    $this->actingAs($user)
        ->get(route('legacy.dashboard'))
        ->assertRedirect("/{$family->slug}/dashboard");
});

test('member lists use family memberships after a member switches to another family', function () {
    $primaryFamily = Family::factory()->create(['name' => 'Primary Family']);
    $secondaryFamily = Family::factory()->create(['name' => 'Secondary Family']);
    $admin = User::factory()->admin()->create([
        'family_id' => $primaryFamily->id,
        'name' => 'Z Admin',
    ]);
    $member = User::factory()->member()->employed()->create([
        'family_id' => $primaryFamily->id,
        'name' => 'A Multi Family Member',
    ]);

    $member->ensureFamilyMembership($secondaryFamily, Role::FinancialSecretary, MemberCategory::Student);
    $member->forceFill([
        'current_family_id' => $secondaryFamily->id,
        'family_id' => $secondaryFamily->id,
        'role' => Role::FinancialSecretary,
        'category' => MemberCategory::Student,
    ])->save();

    expect($member->refresh()->family_id)->toBe($secondaryFamily->id);

    $this->actingAs($admin)
        ->get("/{$primaryFamily->slug}/members")
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Members/Index')
            ->where('members.0.id', $member->id)
            ->where('members.0.role', Role::Member->value)
            ->where('members.0.category', MemberCategory::Employed->value)
            ->where('members.0.monthly_amount', MemberCategory::Employed->monthlyAmount())
        );
});

test('existing users can accept invitations into another family', function () {
    $primaryFamily = Family::factory()->create(['name' => 'Primary Family']);
    $invitedFamily = Family::factory()->create(['name' => 'Invited Family']);
    $user = User::factory()->member()->create([
        'family_id' => $primaryFamily->id,
        'email' => 'existing@example.com',
    ]);
    $invitation = FamilyInvitation::factory()->create([
        'family_id' => $invitedFamily->id,
        'email' => 'existing@example.com',
        'role' => Role::FinancialSecretary,
    ]);

    $this->actingAs($user)
        ->get(route('invitations.accept', $invitation->token))
        ->assertRedirect("/{$invitedFamily->slug}/dashboard")
        ->assertSessionHas('success', "You joined {$invitedFamily->name}.");

    $user->refresh();

    expect($user->belongsToFamily($invitedFamily))->toBeTrue()
        ->and($user->current_family_id)->toBe($invitedFamily->id)
        ->and($user->role)->toBe(Role::FinancialSecretary)
        ->and($invitation->refresh()->accepted_at)->not->toBeNull();
});

test('new users registering from invitations receive membership and current family', function () {
    $family = Family::factory()->create(['name' => 'Invited Family']);
    $invitation = FamilyInvitation::factory()->create([
        'family_id' => $family->id,
        'email' => 'new-member@example.com',
        'role' => Role::Member,
    ]);

    $this->post(route('register.store'), [
        'name' => 'New Member',
        'email' => 'new-member@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
        'invitation_token' => $invitation->token,
    ])->assertRedirect("/{$family->slug}/dashboard");

    $user = User::query()->where('email', 'new-member@example.com')->firstOrFail();

    expect($user->belongsToFamily($family))->toBeTrue()
        ->and($user->current_family_id)->toBe($family->id)
        ->and($user->family_id)->toBe($family->id)
        ->and($invitation->refresh()->accepted_at)->not->toBeNull();
});

test('loaded family memberships power membership helpers and family switcher props', function () {
    $family = Family::factory()->create(['name' => 'Primary Family']);
    $category = FamilyCategory::factory()->create([
        'family_id' => $family->id,
        'name' => 'Patron',
    ]);
    $user = User::factory()->member()->create([
        'family_id' => $family->id,
        'family_category_id' => $category->id,
    ]);

    $user->load(['familyMemberships.family', 'familyMemberships.familyCategory']);

    $membership = $user->currentFamilyMembership();
    $families = $user->toUserFamilies();

    expect($membership)->not->toBeNull()
        ->and($membership?->relationLoaded('familyCategory'))->toBeTrue()
        ->and($user->belongsToFamily($family))->toBeTrue()
        ->and($families)->toHaveCount(1)
        ->and($families->first())->toMatchArray([
            'name' => 'Primary Family',
            'role' => Role::Member->value,
            'category_label' => 'Patron',
            'is_current' => true,
        ]);
});

test('switching to a family without membership fails cleanly', function () {
    $family = Family::factory()->create(['name' => 'Primary Family']);
    $outsideFamily = Family::factory()->create(['name' => 'Outside Family']);
    $user = User::factory()->admin()->create(['family_id' => $family->id]);

    expect($user->switchFamily($outsideFamily))->toBeFalse();

    $user->refresh();

    expect($user->current_family_id)->toBe($family->id)
        ->and($user->family_id)->toBe($family->id)
        ->and($user->role)->toBe(Role::Admin);
});

test('families expose membership rows and memberships expose their user', function () {
    $family = Family::factory()->create(['name' => 'Relationship Family']);
    $user = User::factory()->member()->create(['family_id' => $family->id]);

    $membership = $family->memberships()->firstOrFail();

    expect($membership->user()->first()?->is($user))->toBeTrue();
});

test('existing users can accept invitations matched by whatsapp phone', function () {
    $primaryFamily = Family::factory()->create(['name' => 'Primary Family']);
    $invitedFamily = Family::factory()->create(['name' => 'WhatsApp Invited Family']);
    $user = User::factory()->member()->withVerifiedWhatsApp('+97455550401')->create([
        'family_id' => $primaryFamily->id,
        'email' => 'different@example.com',
    ]);
    $invitation = FamilyInvitation::factory()->viaWhatsApp('+97455550401')->create([
        'family_id' => $invitedFamily->id,
        'role' => Role::FinancialSecretary,
    ]);

    $this->actingAs($user)
        ->get(route('invitations.accept', $invitation->token))
        ->assertRedirect("/{$invitedFamily->slug}/dashboard");

    $user->refresh();

    expect($user->belongsToFamily($invitedFamily))->toBeTrue()
        ->and($user->current_family_id)->toBe($invitedFamily->id)
        ->and($user->role)->toBe(Role::FinancialSecretary)
        ->and($invitation->refresh()->accepted_at)->not->toBeNull();
});

test('legacy family categories still provide monthly contribution amounts', function () {
    $family = Family::factory()->create();
    $category = FamilyCategory::factory()->create([
        'family_id' => $family->id,
        'monthly_amount' => 12345,
    ]);
    $user = User::factory()->member()->create([
        'family_id' => $family->id,
        'family_category_id' => $category->id,
        'category' => null,
    ]);

    $user->familyMemberships()->update([
        'family_category_id' => null,
        'category' => null,
    ]);

    $freshUser = User::query()->findOrFail($user->id);

    expect($freshUser->getMonthlyAmount())->toBe(12345);
});
