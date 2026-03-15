<?php

use App\Enums\Role;
use App\Models\User;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

/**
 * T069 [US4] Feature test for assigning Financial Secretary role
 */
describe('Assign Role', function () {
    beforeEach(function () {
        $this->superAdmin = User::factory()->superAdmin()->create();
    });

    it('super admin can assign financial secretary role to a member', function () {
        $member = User::factory()->member()->create();

        expect($member->role)->toBe(Role::Member);

        $this->actingAs($this->superAdmin)
            ->put("/members/{$member->id}", [
                'name' => $member->name,
                'email' => $member->email,
                'category' => $member->category->value,
                'role' => 'financial_secretary',
            ])
            ->assertRedirect();

        $member->refresh();
        expect($member->role)->toBe(Role::FinancialSecretary);
    });

    it('super admin can assign super admin role to a member', function () {
        $member = User::factory()->member()->create();

        $this->actingAs($this->superAdmin)
            ->put("/members/{$member->id}", [
                'name' => $member->name,
                'email' => $member->email,
                'category' => $member->category->value,
                'role' => 'super_admin',
            ])
            ->assertRedirect();

        $member->refresh();
        expect($member->role)->toBe(Role::SuperAdmin);
    });

    it('financial secretary cannot assign roles', function () {
        $financialSecretary = User::factory()->financialSecretary()->create();
        $member = User::factory()->member()->create();

        $this->actingAs($financialSecretary)
            ->put("/members/{$member->id}", [
                'name' => $member->name,
                'email' => $member->email,
                'category' => $member->category->value,
                'role' => 'financial_secretary',
            ])
            ->assertForbidden();

        $member->refresh();
        expect($member->role)->toBe(Role::Member);
    });

    it('member cannot assign roles', function () {
        $regularMember = User::factory()->member()->create();
        $targetMember = User::factory()->member()->create();

        $this->actingAs($regularMember)
            ->put("/members/{$targetMember->id}", [
                'name' => $targetMember->name,
                'email' => $targetMember->email,
                'category' => $targetMember->category->value,
                'role' => 'financial_secretary',
            ])
            ->assertForbidden();
    });

    it('assigned financial secretary can record payments', function () {
        $member = User::factory()->member()->create();

        // Assign FS role
        $this->actingAs($this->superAdmin)
            ->put("/members/{$member->id}", [
                'name' => $member->name,
                'email' => $member->email,
                'category' => $member->category->value,
                'role' => 'financial_secretary',
            ]);

        $member->refresh();

        // Verify can record payments
        expect($member->canRecordPayments())->toBeTrue();
    });

    it('returns success flash message after role assignment', function () {
        $member = User::factory()->member()->create();

        $response = $this->actingAs($this->superAdmin)
            ->put("/members/{$member->id}", [
                'name' => $member->name,
                'email' => $member->email,
                'category' => $member->category->value,
                'role' => 'financial_secretary',
            ]);

        $response->assertSessionHas('success');
    });
});
