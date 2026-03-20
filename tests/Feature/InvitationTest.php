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
