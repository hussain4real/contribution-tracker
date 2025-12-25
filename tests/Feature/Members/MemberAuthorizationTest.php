<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * T058 [US3] Feature test for member management authorization
 */
describe('Member Authorization', function () {
    beforeEach(function () {
        $this->superAdmin = User::factory()->superAdmin()->create();
        $this->financialSecretary = User::factory()->financialSecretary()->create();
        $this->member = User::factory()->member()->employed()->create();
        $this->targetMember = User::factory()->member()->student()->create();
    });

    // Index - All authenticated users can view list
    it('super admin can view members index', function () {
        $this->actingAs($this->superAdmin)
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

    // Create - Only Super Admin
    it('super admin can access create form', function () {
        $this->actingAs($this->superAdmin)
            ->get('/members/create')
            ->assertOk();
    });

    it('financial secretary cannot access create form', function () {
        $this->actingAs($this->financialSecretary)
            ->get('/members/create')
            ->assertForbidden();
    });

    it('member cannot access create form', function () {
        $this->actingAs($this->member)
            ->get('/members/create')
            ->assertForbidden();
    });

    // Store - Only Super Admin
    it('super admin can create member', function () {
        $this->actingAs($this->superAdmin)
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

    it('financial secretary cannot create member', function () {
        $this->actingAs($this->financialSecretary)
            ->post('/members', [
                'name' => 'New Member',
                'email' => 'new@example.com',
                'password' => 'password123',
                'password_confirmation' => 'password123',
                'category' => 'employed',
                'role' => 'member',
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('users', ['email' => 'new@example.com']);
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

    // Edit - Only Super Admin
    it('super admin can access edit form', function () {
        $this->actingAs($this->superAdmin)
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

    // Update - Only Super Admin
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

    // Delete - Only Super Admin
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

    // Restore - Only Super Admin
    it('financial secretary cannot restore member', function () {
        $archivedMember = User::factory()->member()->archived()->create();

        $this->actingAs($this->financialSecretary)
            ->post("/members/{$archivedMember->id}/restore")
            ->assertForbidden();
    });
});
