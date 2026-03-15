<?php

use App\Enums\MemberCategory;
use App\Enums\Role;
use App\Models\User;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

/**
 * T070 [US4] Feature test for revoking Financial Secretary role
 */
describe('Revoke Role', function () {
    beforeEach(function () {
        $this->superAdmin = User::factory()->superAdmin()->create();
    });

    it('super admin can revoke financial secretary role', function () {
        $financialSecretary = User::factory()->financialSecretary()->create();

        expect($financialSecretary->role)->toBe(Role::FinancialSecretary);

        $this->actingAs($this->superAdmin)
            ->put("/members/{$financialSecretary->id}", [
                'name' => $financialSecretary->name,
                'email' => $financialSecretary->email,
                'category' => $financialSecretary->category->value,
                'role' => 'member',
            ])
            ->assertRedirect();

        $financialSecretary->refresh();
        expect($financialSecretary->role)->toBe(Role::Member);
    });

    it('revoked financial secretary cannot record payments', function () {
        $financialSecretary = User::factory()->financialSecretary()->create();

        // Verify can record payments before revocation
        expect($financialSecretary->canRecordPayments())->toBeTrue();

        // Revoke role
        $this->actingAs($this->superAdmin)
            ->put("/members/{$financialSecretary->id}", [
                'name' => $financialSecretary->name,
                'email' => $financialSecretary->email,
                'category' => $financialSecretary->category->value,
                'role' => 'member',
            ]);

        $financialSecretary->refresh();

        // Verify cannot record payments after revocation
        expect($financialSecretary->canRecordPayments())->toBeFalse();
    });

    it('super admin can demote another super admin to member', function () {
        // Create another super admin with a category so they can be demoted
        $anotherAdmin = User::factory()->superAdmin()->employed()->create();

        $this->actingAs($this->superAdmin)
            ->put("/members/{$anotherAdmin->id}", [
                'name' => $anotherAdmin->name,
                'email' => $anotherAdmin->email,
                'category' => $anotherAdmin->category->value,
                'role' => 'member',
            ])
            ->assertRedirect();

        $anotherAdmin->refresh();
        expect($anotherAdmin->role)->toBe(Role::Member);
    });

    it('super admin cannot demote themselves', function () {
        // Give super admin a category for the form submission
        $this->superAdmin->update(['category' => MemberCategory::Employed]);

        $response = $this->actingAs($this->superAdmin)
            ->put("/members/{$this->superAdmin->id}", [
                'name' => $this->superAdmin->name,
                'email' => $this->superAdmin->email,
                'category' => $this->superAdmin->category->value,
                'role' => 'member',
            ]);

        // Should either be forbidden or redirect with error
        $this->superAdmin->refresh();
        expect($this->superAdmin->role)->toBe(Role::SuperAdmin);
    });

    it('financial secretary cannot revoke roles', function () {
        $financialSecretary = User::factory()->financialSecretary()->create();
        $anotherFs = User::factory()->financialSecretary()->create();

        $this->actingAs($financialSecretary)
            ->put("/members/{$anotherFs->id}", [
                'name' => $anotherFs->name,
                'email' => $anotherFs->email,
                'category' => $anotherFs->category->value,
                'role' => 'member',
            ])
            ->assertForbidden();

        $anotherFs->refresh();
        expect($anotherFs->role)->toBe(Role::FinancialSecretary);
    });
});
