<?php

use App\Enums\Role;
use App\Models\Family;
use App\Models\FamilyInvitation;
use App\Models\User;

describe('Invitation Store', function () {
    it('prevents inviting a user who is already a family member', function () {
        $family = Family::factory()->create();
        $admin = User::factory()->admin()->create(['family_id' => $family->id]);
        $existingMember = User::factory()->member()->create([
            'family_id' => $family->id,
            'email' => 'member@example.com',
        ]);

        $this->actingAs($admin)
            ->post('/family/invitations', [
                'email' => 'member@example.com',
                'role' => Role::Member->value,
            ])
            ->assertRedirect()
            ->assertSessionHas('error', 'This user is already a member of your family.');

        expect(FamilyInvitation::count())->toBe(0);
    });

    it('prevents duplicate pending invitations', function () {
        $family = Family::factory()->create();
        $admin = User::factory()->admin()->create(['family_id' => $family->id]);

        FamilyInvitation::factory()->create([
            'family_id' => $family->id,
            'email' => 'new@example.com',
            'expires_at' => now()->addDays(7),
        ]);

        $this->actingAs($admin)
            ->post('/family/invitations', [
                'email' => 'new@example.com',
                'role' => Role::Member->value,
            ])
            ->assertRedirect()
            ->assertSessionHas('error', 'An invitation is already pending for this email.');
    });

    it('allows inviting a new email address', function () {
        $family = Family::factory()->create();
        $admin = User::factory()->admin()->create(['family_id' => $family->id]);

        $this->actingAs($admin)
            ->post('/family/invitations', [
                'email' => 'new@example.com',
                'role' => Role::Member->value,
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        expect(FamilyInvitation::where('email', 'new@example.com')->count())->toBe(1);
    });

    it('prevents non-admin from sending invitations', function () {
        $family = Family::factory()->create();
        $member = User::factory()->member()->create(['family_id' => $family->id]);

        $this->actingAs($member)
            ->post('/family/invitations', [
                'email' => 'new@example.com',
                'role' => Role::Member->value,
            ])
            ->assertForbidden();
    });
});

describe('Invitation Index', function () {
    it('lists family invitations for admins', function () {
        $family = Family::factory()->create(['name' => 'Smith Family']);
        $otherFamily = Family::factory()->create();
        $admin = User::factory()->admin()->create(['family_id' => $family->id]);
        $inviter = User::factory()->admin()->create(['family_id' => $family->id, 'name' => 'Ada Admin']);

        FamilyInvitation::factory()->create([
            'family_id' => $family->id,
            'email' => 'first@example.com',
            'role' => Role::FinancialSecretary,
            'invited_by' => $inviter->id,
            'expires_at' => now()->addDays(7),
        ]);
        FamilyInvitation::factory()->create([
            'family_id' => $otherFamily->id,
            'email' => 'other@example.com',
        ]);

        $this->actingAs($admin)
            ->get(route('family.invitations'))
            ->assertSuccessful()
            ->assertInertia(fn ($page) => $page
                ->component('Family/Invitations')
                ->where('family_name', 'Smith Family')
                ->has('invitations', 1)
                ->where('invitations.0.email', 'first@example.com')
                ->where('invitations.0.role', Role::FinancialSecretary->value)
                ->where('invitations.0.role_label', 'Financial Secretary')
                ->where('invitations.0.invited_by', 'Ada Admin')
                ->where('invitations.0.is_pending', true)
            );
    });

    it('prevents non-admins from listing invitations', function () {
        $member = User::factory()->member()->create();

        $this->actingAs($member)
            ->get(route('family.invitations'))
            ->assertForbidden();
    });
});

describe('Invitation Destroy', function () {
    it('allows admins to cancel invitations in their family', function () {
        $family = Family::factory()->create();
        $admin = User::factory()->admin()->create(['family_id' => $family->id]);
        $invitation = FamilyInvitation::factory()->create(['family_id' => $family->id]);

        $this->actingAs($admin)
            ->delete(route('family.invitations.destroy', $invitation))
            ->assertRedirect()
            ->assertSessionHas('success', 'Invitation cancelled.');

        expect(FamilyInvitation::whereKey($invitation->id)->exists())->toBeFalse();
    });

    it('prevents admins from cancelling invitations in another family', function () {
        $admin = User::factory()->admin()->create();
        $invitation = FamilyInvitation::factory()->create();

        $this->actingAs($admin)
            ->delete(route('family.invitations.destroy', $invitation))
            ->assertForbidden();
    });

    it('prevents non-admins from cancelling invitations', function () {
        $family = Family::factory()->create();
        $member = User::factory()->member()->create(['family_id' => $family->id]);
        $invitation = FamilyInvitation::factory()->create(['family_id' => $family->id]);

        $this->actingAs($member)
            ->delete(route('family.invitations.destroy', $invitation))
            ->assertForbidden();
    });
});

describe('Invitation Accept', function () {
    it('renders a valid invitation acceptance page', function () {
        $family = Family::factory()->create(['name' => 'Smith Family']);
        $invitation = FamilyInvitation::factory()->create([
            'family_id' => $family->id,
            'email' => 'guest@example.com',
            'role' => Role::Member,
            'token' => 'valid-token',
            'expires_at' => now()->addDay(),
        ]);

        $this->get(route('invitations.accept', $invitation->token))
            ->assertSuccessful()
            ->assertInertia(fn ($page) => $page
                ->component('auth/AcceptInvitation')
                ->where('invitation.token', 'valid-token')
                ->where('invitation.email', 'guest@example.com')
                ->where('invitation.family_name', 'Smith Family')
                ->where('invitation.role_label', 'Member')
            );
    });

    it('redirects missing or accepted invitations', function (?FamilyInvitation $invitation, string $token) {
        $this->get(route('invitations.accept', $invitation?->token ?? $token))
            ->assertRedirect(route('home'))
            ->assertSessionHas('error', 'This invitation is no longer valid.');
    })->with([
        'missing' => [null, 'missing-token'],
        'accepted' => fn () => [FamilyInvitation::factory()->accepted()->create(), 'unused'],
    ]);

    it('redirects expired invitations', function () {
        $invitation = FamilyInvitation::factory()->expired()->create();

        $this->get(route('invitations.accept', $invitation->token))
            ->assertRedirect(route('home'))
            ->assertSessionHas('error', 'This invitation has expired.');
    });
});
