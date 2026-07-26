<?php

declare(strict_types=1);

use App\Enums\MemberCategory;
use App\Enums\Role;
use App\Models\FamilyCategory;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * T054 [US3] Feature test for creating new member
 */
describe('Create Member', function () {
    beforeEach(function () {
        $this->admin = User::factory()->admin()->create();
        $this->employed = FamilyCategory::factory()->create([
            'family_id' => $this->admin->family_id,
            'name' => 'Employed',
            'slug' => 'employed',
            'monthly_amount' => 4000,
        ]);
        $this->student = FamilyCategory::factory()->create([
            'family_id' => $this->admin->family_id,
            'name' => 'Student',
            'slug' => 'student',
            'monthly_amount' => 1000,
        ]);
    });

    it('super admin can access member creation form', function () {
        $this->actingAs($this->admin)
            ->get('/members/create')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Members/Create')
                ->has('categories')
                ->has('roles')
            );
    });

    it('super admin can create a new member', function () {
        $this->actingAs($this->admin)
            ->post('/members', [
                'name' => 'John Doe',
                'email' => 'john@example.com',
                'password' => 'password123',
                'password_confirmation' => 'password123',
                'family_category_id' => $this->employed->id,
                'role' => 'member',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('users', [
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'category' => MemberCategory::Employed->value,
            'role' => Role::Member->value,
        ]);
        $this->assertDatabaseHas('family_members', [
            'family_id' => $this->admin->family_id,
            'display_name' => 'John Doe',
            'family_category_id' => $this->employed->id,
            'role' => Role::Member->value,
        ]);
    });

    it('creates student member with correct expected amount', function () {
        $this->actingAs($this->admin)
            ->post('/members', [
                'name' => 'Jane Student',
                'email' => 'jane@example.com',
                'password' => 'password123',
                'password_confirmation' => 'password123',
                'family_category_id' => $this->student->id,
                'role' => 'member',
            ])
            ->assertRedirect();

        $member = User::where('email', 'jane@example.com')->firstOrFail();
        expect($member->category)->toBe(MemberCategory::Student);
        expect($member->getMonthlyAmount())->toBe(1000); // ₦1,000
    });

    it('validates required fields', function () {
        $this->actingAs($this->admin)
            ->post('/members', [])
            ->assertSessionHasErrors(['name', 'email', 'password', 'family_category_id']);
    });

    it('validates unique email', function () {
        User::factory()->create(['email' => 'existing@example.com']);

        $this->actingAs($this->admin)
            ->post('/members', [
                'name' => 'New User',
                'email' => 'existing@example.com',
                'password' => 'password123',
                'password_confirmation' => 'password123',
                'family_category_id' => $this->employed->id,
                'role' => 'member',
            ])
            ->assertSessionHasErrors(['email']);
    });

    it('validates password confirmation', function () {
        $this->actingAs($this->admin)
            ->post('/members', [
                'name' => 'New User',
                'email' => 'new@example.com',
                'password' => 'password123',
                'password_confirmation' => 'different',
                'family_category_id' => $this->employed->id,
                'role' => 'member',
            ])
            ->assertSessionHasErrors(['password']);
    });
});
