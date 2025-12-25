<?php

use App\Enums\MemberCategory;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * T055 [US3] Feature test for editing member category
 */
describe('Update Member', function () {
    beforeEach(function () {
        $this->superAdmin = User::factory()->superAdmin()->create();
        $this->member = User::factory()->member()->employed()->create();
    });

    it('super admin can access member edit form', function () {
        $this->actingAs($this->superAdmin)
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

    it('super admin can update member name', function () {
        $this->actingAs($this->superAdmin)
            ->put("/members/{$this->member->id}", [
                'name' => 'Updated Name',
                'email' => $this->member->email,
                'category' => $this->member->category->value,
                'role' => $this->member->role->value,
            ])
            ->assertRedirect();

        $this->member->refresh();
        expect($this->member->name)->toBe('Updated Name');
    });

    it('super admin can update member category', function () {
        $this->actingAs($this->superAdmin)
            ->put("/members/{$this->member->id}", [
                'name' => $this->member->name,
                'email' => $this->member->email,
                'category' => 'student',
                'role' => $this->member->role->value,
            ])
            ->assertRedirect();

        $this->member->refresh();
        expect($this->member->category)->toBe(MemberCategory::Student);
    });

    it('category change affects expected amount', function () {
        // Initially employed (₦4,000)
        expect($this->member->getMonthlyAmountInKobo())->toBe(400000);

        $this->actingAs($this->superAdmin)
            ->put("/members/{$this->member->id}", [
                'name' => $this->member->name,
                'email' => $this->member->email,
                'category' => 'unemployed',
                'role' => $this->member->role->value,
            ])
            ->assertRedirect();

        $this->member->refresh();
        // Now unemployed (₦2,000)
        expect($this->member->getMonthlyAmountInKobo())->toBe(200000);
    });

    it('validates required fields on update', function () {
        $this->actingAs($this->superAdmin)
            ->put("/members/{$this->member->id}", [])
            ->assertSessionHasErrors(['name', 'email', 'category']);
    });

    it('validates unique email excludes current member', function () {
        // Create another member
        $otherMember = User::factory()->member()->create(['email' => 'other@example.com']);

        // Try to update to the other member's email
        $this->actingAs($this->superAdmin)
            ->put("/members/{$this->member->id}", [
                'name' => $this->member->name,
                'email' => 'other@example.com',
                'category' => $this->member->category->value,
                'role' => $this->member->role->value,
            ])
            ->assertSessionHasErrors(['email']);
    });

    it('can keep same email on update', function () {
        $this->actingAs($this->superAdmin)
            ->put("/members/{$this->member->id}", [
                'name' => 'New Name',
                'email' => $this->member->email, // Same email
                'category' => $this->member->category->value,
                'role' => $this->member->role->value,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();
    });
});
