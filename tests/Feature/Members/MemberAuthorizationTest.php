<?php

declare(strict_types=1);

use App\Models\Contribution;
use App\Models\Family;
use App\Models\Payment;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * T058 [US3] Feature test for member management authorization
 */
describe('Member Authorization', function () {
    beforeEach(function () {
        $this->family = Family::factory()->create();
        $this->admin = User::factory()->admin()->create(['family_id' => $this->family->id]);
        $this->financialSecretary = User::factory()->financialSecretary()->create(['family_id' => $this->family->id]);
        $this->member = User::factory()->member()->employed()->create(['family_id' => $this->family->id]);
        $this->targetMember = User::factory()->member()->student()->create(['family_id' => $this->family->id]);
    });

    // Index - All authenticated users can view list
    it('super admin can view members index', function () {
        $this->actingAs($this->admin)
            ->get('/members')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Members/Index')
            );
    });

    it('financial secretary can view members index', function () {
        $this->actingAs($this->financialSecretary)
            ->get('/members')
            ->assertOk();
    });

    it('member can view members index', function () {
        $this->actingAs($this->member)
            ->get('/members')
            ->assertOk();
    });

    // Show - Admin and Financial Secretary can view any member, regular members can only view their own
    it('super admin can view any member profile', function () {
        $contribution = Contribution::factory()
            ->forUser($this->targetMember)
            ->currentMonth()
            ->create(['expected_amount' => 4000]);
        Payment::factory()
            ->forContribution($contribution)
            ->recordedBy($this->admin)
            ->create(['amount' => 1500, 'notes' => 'Cash']);

        $this->actingAs($this->admin)
            ->get("/members/{$this->targetMember->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Members/Show')
                ->has('contributions')
                ->where('contributions.0.payments.0.amount', 1500)
                ->where('contributions.0.payments.0.notes', 'Cash')
                ->where('contributions.0.payments.0.recorder.name', $this->admin->name)
            );
    });

    it('financial secretary can view any member profile', function () {
        $this->actingAs($this->financialSecretary)
            ->get("/members/{$this->targetMember->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Members/Show')
                ->has('contributions')
            );
    });

    it('member can view their own profile with contributions', function () {
        $this->actingAs($this->member)
            ->get("/members/{$this->member->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Members/Show')
                ->where('canViewContributions', true)
            );
    });

    it('member can view other member basic info but not contributions', function () {
        $this->actingAs($this->member)
            ->get("/members/{$this->targetMember->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Members/Show')
                ->where('canViewContributions', false)
                ->where('contributions', [])
            );
    });

    // Create - Admin and Financial Secretary can add ordinary members
    it('super admin can access create form', function () {
        $this->actingAs($this->admin)
            ->get('/members/create')
            ->assertOk();
    });

    it('financial secretary can access create form with only member role available', function () {
        $this->actingAs($this->financialSecretary)
            ->get('/members/create')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Members/Create')
                ->has('roles', 1)
                ->where('roles.0.value', 'member')
            );
    });

    it('member cannot access create form', function () {
        $this->actingAs($this->member)
            ->get('/members/create')
            ->assertForbidden();
    });

    // Store - Admin and Financial Secretary can add ordinary members
    it('super admin can create member', function () {
        $this->actingAs($this->admin)
            ->post('/members', [
                'name' => 'New Member',
                'email' => 'new@example.com',
                'password' => 'password123',
                'password_confirmation' => 'password123',
                'category' => 'employed',
                'role' => 'member',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('users', ['email' => 'new@example.com']);
    });

    it('financial secretary can create ordinary member', function () {
        $this->actingAs($this->financialSecretary)
            ->post('/members', [
                'name' => 'New Member',
                'email' => 'new@example.com',
                'password' => 'password123',
                'password_confirmation' => 'password123',
                'category' => 'employed',
                'role' => 'member',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('users', [
            'email' => 'new@example.com',
            'role' => 'member',
        ]);
    });

    it('financial secretary cannot create privileged member', function () {
        $this->actingAs($this->financialSecretary)
            ->post('/members', [
                'name' => 'New Admin',
                'email' => 'new-admin@example.com',
                'password' => 'password123',
                'password_confirmation' => 'password123',
                'category' => 'employed',
                'role' => 'admin',
            ])
            ->assertSessionHasErrors('role');

        $this->assertDatabaseMissing('users', ['email' => 'new-admin@example.com']);
    });

    it('member cannot create member', function () {
        $this->actingAs($this->member)
            ->post('/members', [
                'name' => 'New Member',
                'email' => 'new@example.com',
                'password' => 'password123',
                'password_confirmation' => 'password123',
                'category' => 'employed',
                'role' => 'member',
            ])
            ->assertForbidden();
    });

    // Edit - Only Admin
    it('super admin can access edit form', function () {
        $this->actingAs($this->admin)
            ->get("/members/{$this->targetMember->id}/edit")
            ->assertOk();
    });

    it('financial secretary cannot access edit form', function () {
        $this->actingAs($this->financialSecretary)
            ->get("/members/{$this->targetMember->id}/edit")
            ->assertForbidden();
    });

    it('member cannot access edit form', function () {
        $this->actingAs($this->member)
            ->get("/members/{$this->targetMember->id}/edit")
            ->assertForbidden();
    });

    // Update - Only Admin
    it('financial secretary cannot update member', function () {
        $this->actingAs($this->financialSecretary)
            ->put("/members/{$this->targetMember->id}", [
                'name' => 'Updated Name',
                'email' => $this->targetMember->email,
                'category' => 'employed',
                'role' => 'member',
            ])
            ->assertForbidden();
    });

    // Delete - Only Admin
    it('financial secretary cannot archive member', function () {
        $this->actingAs($this->financialSecretary)
            ->delete("/members/{$this->targetMember->id}")
            ->assertForbidden();

        $this->targetMember->refresh();
        expect($this->targetMember->isArchived())->toBeFalse();
    });

    it('member cannot archive member', function () {
        $this->actingAs($this->member)
            ->delete("/members/{$this->targetMember->id}")
            ->assertForbidden();
    });

    // Restore - Only Admin
    it('financial secretary cannot restore member', function () {
        $archivedMember = User::factory()->member()->archived()->create(['family_id' => $this->family->id]);

        $this->actingAs($this->financialSecretary)
            ->post("/members/{$archivedMember->id}/restore")
            ->assertForbidden();
    });
});
