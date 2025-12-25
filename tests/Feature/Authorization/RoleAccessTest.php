<?php

use App\Enums\Role;
use App\Models\Contribution;
use App\Models\User;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

/**
 * T071 [US4] Feature test for verifying role-based access after assignment
 */
describe('Role Access', function () {
    beforeEach(function () {
        $this->superAdmin = User::factory()->superAdmin()->create();
        $this->member = User::factory()->member()->employed()->create();
        $this->contribution = Contribution::factory()
            ->forUser($this->member)
            ->currentMonth()
            ->create();
    });

    it('newly assigned financial secretary can access payment form', function () {
        $newFs = User::factory()->member()->create();

        // Assign FS role
        $this->actingAs($this->superAdmin)
            ->put("/members/{$newFs->id}", [
                'name' => $newFs->name,
                'email' => $newFs->email,
                'category' => $newFs->category->value,
                'role' => 'financial_secretary',
            ]);

        $newFs->refresh();

        // Verify can access payment form
        $this->actingAs($newFs)
            ->get(route('payments.create', $this->member))
            ->assertOk();
    });

    it('newly assigned financial secretary can record payments', function () {
        $newFs = User::factory()->member()->create();

        // Assign FS role
        $this->actingAs($this->superAdmin)
            ->put("/members/{$newFs->id}", [
                'name' => $newFs->name,
                'email' => $newFs->email,
                'category' => $newFs->category->value,
                'role' => 'financial_secretary',
            ]);

        $newFs->refresh();

        // Verify can record payment
        $this->actingAs($newFs)
            ->post(route('payments.store'), [
                'member_id' => $this->member->id,
                'amount' => 100000,
                'paid_at' => now()->toDateString(),
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('payments', [
            'amount' => 100000,
            'recorded_by' => $newFs->id,
        ]);
    });

    it('demoted financial secretary cannot access payment form', function () {
        $fs = User::factory()->financialSecretary()->create();

        // Verify can access before demotion
        $this->actingAs($fs)
            ->get(route('payments.create', $this->member))
            ->assertOk();

        // Demote to member
        $this->actingAs($this->superAdmin)
            ->put("/members/{$fs->id}", [
                'name' => $fs->name,
                'email' => $fs->email,
                'category' => $fs->category->value,
                'role' => 'member',
            ]);

        $fs->refresh();

        // Verify cannot access after demotion
        $this->actingAs($fs)
            ->get(route('payments.create', $this->member))
            ->assertForbidden();
    });

    it('demoted financial secretary cannot record payments', function () {
        $fs = User::factory()->financialSecretary()->create();

        // Demote to member
        $this->actingAs($this->superAdmin)
            ->put("/members/{$fs->id}", [
                'name' => $fs->name,
                'email' => $fs->email,
                'category' => $fs->category->value,
                'role' => 'member',
            ]);

        $fs->refresh();

        // Verify cannot record payment
        $this->actingAs($fs)
            ->post(route('payments.store'), [
                'member_id' => $this->member->id,
                'amount' => 100000,
                'paid_at' => now()->toDateString(),
            ])
            ->assertForbidden();
    });

    it('newly promoted super admin can manage members', function () {
        $newAdmin = User::factory()->member()->create();

        // Promote to super admin
        $this->actingAs($this->superAdmin)
            ->put("/members/{$newAdmin->id}", [
                'name' => $newAdmin->name,
                'email' => $newAdmin->email,
                'category' => $newAdmin->category->value,
                'role' => 'super_admin',
            ]);

        $newAdmin->refresh();

        // Verify can access member creation
        $this->actingAs($newAdmin)
            ->get(route('members.create'))
            ->assertOk();
    });

    it('newly promoted super admin can assign roles', function () {
        $newAdmin = User::factory()->member()->create();
        $targetMember = User::factory()->member()->create();

        // Promote to super admin
        $this->actingAs($this->superAdmin)
            ->put("/members/{$newAdmin->id}", [
                'name' => $newAdmin->name,
                'email' => $newAdmin->email,
                'category' => $newAdmin->category->value,
                'role' => 'super_admin',
            ]);

        $newAdmin->refresh();

        // Verify can assign role
        $this->actingAs($newAdmin)
            ->put("/members/{$targetMember->id}", [
                'name' => $targetMember->name,
                'email' => $targetMember->email,
                'category' => $targetMember->category->value,
                'role' => 'financial_secretary',
            ])
            ->assertRedirect();

        $targetMember->refresh();
        expect($targetMember->role)->toBe(Role::FinancialSecretary);
    });

    it('demoted super admin cannot manage members', function () {
        // Create another admin with a category
        $anotherAdmin = User::factory()->superAdmin()->employed()->create();

        // Demote to member
        $this->actingAs($this->superAdmin)
            ->put("/members/{$anotherAdmin->id}", [
                'name' => $anotherAdmin->name,
                'email' => $anotherAdmin->email,
                'category' => $anotherAdmin->category->value,
                'role' => 'member',
            ]);

        $anotherAdmin->refresh();

        // Verify cannot access member creation
        $this->actingAs($anotherAdmin)
            ->get(route('members.create'))
            ->assertForbidden();
    });
});
