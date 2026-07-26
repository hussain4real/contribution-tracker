<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Models\Family;
use App\Models\FamilyCategory;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * T055 [US3] Feature test for editing member category
 */
describe('Update Member', function () {
    beforeEach(function () {
        $this->family = Family::factory()->create();
        $this->admin = User::factory()->admin()->create(['family_id' => $this->family->id]);
        $this->member = User::factory()->member()->employed()->create(['family_id' => $this->family->id]);
        $this->employed = FamilyCategory::query()
            ->where('family_id', $this->family->id)
            ->where('slug', 'employed')
            ->firstOrFail();
        $this->student = FamilyCategory::factory()->create([
            'family_id' => $this->family->id,
            'name' => 'Student',
            'slug' => 'student',
            'monthly_amount' => 1000,
        ]);
        $this->unemployed = FamilyCategory::factory()->create([
            'family_id' => $this->family->id,
            'name' => 'Unemployed',
            'slug' => 'unemployed',
            'monthly_amount' => 2000,
        ]);
    });

    it('super admin can access member edit form', function () {
        $this->actingAs($this->admin)
            ->get("/members/{$this->member->id}/edit")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Members/Edit')
                ->has('member')
                ->where('member.id', $this->member->id)
                ->where('member.category', 'employed')
                ->has('categories')
                ->has('roles')
            );
    });

    it('super admin can update the family display name without changing global name', function () {
        $this->actingAs($this->admin)
            ->put("/members/{$this->member->id}", [
                'display_name' => 'Updated Name',
                'family_category_id' => $this->employed->id,
                'role' => $this->member->role->value,
            ])
            ->assertRedirect();

        expect($this->member->refresh()->name)->not->toBe('Updated Name')
            ->and($this->member->membershipForFamily($this->family)?->display_name)->toBe('Updated Name');
    });

    it('super admin can update member category', function () {
        $this->actingAs($this->admin)
            ->put("/members/{$this->member->id}", [
                'display_name' => $this->member->name,
                'family_category_id' => $this->student->id,
                'role' => $this->member->role->value,
                'effective_immediately' => true,
            ])
            ->assertRedirect();

        expect($this->member->membershipForFamily($this->family)?->family_category_id)->toBe($this->student->id);
    });

    it('category change affects expected amount', function () {
        // Initially employed (₦4,000)
        expect($this->member->getMonthlyAmount())->toBe(4000);

        $this->actingAs($this->admin)
            ->put("/members/{$this->member->id}", [
                'display_name' => $this->member->name,
                'family_category_id' => $this->unemployed->id,
                'role' => $this->member->role->value,
                'effective_immediately' => true,
            ])
            ->assertRedirect();

        $this->member->refresh();
        // Now unemployed (₦2,000)
        expect($this->member->getMonthlyAmount())->toBe(2000)
            ->and($this->member->currentFamilyMembership()?->monthlyAmount())->toBe(2000);
    });

    it('validates required fields on update', function () {
        $this->actingAs($this->admin)
            ->put("/members/{$this->member->id}", [])
            ->assertSessionHasErrors(['display_name', 'family_category_id', 'role']);
    });

    it('does not let a family administrator change a members global email', function () {
        $otherMember = User::factory()->member()->create([
            'family_id' => $this->family->id,
            'email' => 'other@example.com',
        ]);

        $this->actingAs($this->admin)
            ->put("/members/{$this->member->id}", [
                'display_name' => $this->member->name,
                'email' => 'other@example.com',
                'family_category_id' => $this->employed->id,
                'role' => $this->member->role->value,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        expect($this->member->refresh()->email)->not->toBe('other@example.com');
    });

    it('can keep same email on update', function () {
        $this->actingAs($this->admin)
            ->put("/members/{$this->member->id}", [
                'display_name' => 'New Name',
                'email' => $this->member->email, // Same email
                'family_category_id' => $this->employed->id,
                'role' => $this->member->role->value,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();
    });

    it('does not let a family administrator change a members global password', function () {
        $originalPassword = $this->member->password;

        $this->actingAs($this->admin)
            ->put("/members/{$this->member->id}", [
                'display_name' => $this->member->name,
                'family_category_id' => $this->employed->id,
                'role' => $this->member->role->value,
                'password' => 'new-password',
                'password_confirmation' => 'new-password',
            ])
            ->assertRedirect();

        expect($this->member->refresh()->password)->toBe($originalPassword);
    });

    it('does not let a family administrator demote their own membership', function () {
        $this->actingAs($this->admin)
            ->put("/members/{$this->admin->id}", [
                'display_name' => $this->admin->name,
                'family_category_id' => $this->employed->id,
                'role' => Role::Member->value,
            ])
            ->assertRedirect();

        expect($this->admin->membershipForFamily($this->family)?->role)->toBe(Role::Admin);
    });
});
