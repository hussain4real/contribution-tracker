<?php

use App\Enums\MemberCategory;
use App\Enums\Role;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * T054 [US3] Feature test for creating new member
 */
describe('Create Member', function () {
    beforeEach(function () {
        $this->superAdmin = User::factory()->superAdmin()->create();
    });

    it('super admin can access member creation form', function () {
        $this->actingAs($this->superAdmin)
            ->get('/members/create')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Members/Create')
                ->has('categories')
                ->has('roles')
            );
    });

    it('super admin can create a new member', function () {
        $this->actingAs($this->superAdmin)
            ->post('/members', [
                'name' => 'John Doe',
                'email' => 'john@example.com',
                'password' => 'password123',
                'password_confirmation' => 'password123',
                'category' => 'employed',
                'role' => 'member',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('users', [
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'category' => MemberCategory::Employed->value,
            'role' => Role::Member->value,
        ]);
    });

    it('creates student member with correct expected amount', function () {
        $this->actingAs($this->superAdmin)
            ->post('/members', [
                'name' => 'Jane Student',
                'email' => 'jane@example.com',
                'password' => 'password123',
                'password_confirmation' => 'password123',
                'category' => 'student',
                'role' => 'member',
            ])
            ->assertRedirect();

        $member = User::where('email', 'jane@example.com')->first();
        expect($member->category)->toBe(MemberCategory::Student);
        expect($member->getMonthlyAmountInKobo())->toBe(100000); // ₦1,000
    });

    it('validates required fields', function () {
        $this->actingAs($this->superAdmin)
            ->post('/members', [])
            ->assertSessionHasErrors(['name', 'email', 'password', 'category']);
    });

    it('validates unique email', function () {
        User::factory()->create(['email' => 'existing@example.com']);

        $this->actingAs($this->superAdmin)
            ->post('/members', [
                'name' => 'New User',
                'email' => 'existing@example.com',
                'password' => 'password123',
                'password_confirmation' => 'password123',
                'category' => 'employed',
                'role' => 'member',
            ])
            ->assertSessionHasErrors(['email']);
    });

    it('validates password confirmation', function () {
        $this->actingAs($this->superAdmin)
            ->post('/members', [
                'name' => 'New User',
                'email' => 'new@example.com',
                'password' => 'password123',
                'password_confirmation' => 'different',
                'category' => 'employed',
                'role' => 'member',
            ])
            ->assertSessionHasErrors(['password']);
    });
});
