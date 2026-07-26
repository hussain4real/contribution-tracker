<?php

declare(strict_types=1);

use App\Enums\MemberCategory;
use App\Enums\Role;
use App\Models\Contribution;
use App\Models\Family;
use App\Models\User;

/**
 * T071 [US4] Feature test for verifying role-based access after assignment
 */
describe('Role Access', function () {
    beforeEach(function () {
        $this->family = Family::factory()->create();
        $this->admin = User::factory()->admin()->create(['family_id' => $this->family->id]);
        $this->member = User::factory()->member()->employed()->create(['family_id' => $this->family->id]);
        $this->contribution = Contribution::factory()
            ->forUser($this->member)
            ->currentMonth()
            ->create();
    });

    it('newly assigned financial secretary can access payment form', function () {
        $newFs = User::factory()->member()->create(['family_id' => $this->family->id]);

        // Assign FS role
        $this->actingAs($this->admin)
            ->put("/members/{$newFs->id}", [
                'name' => $newFs->name,
                'email' => $newFs->email,
                'category' => MemberCategory::Employed->value,
                'role' => 'financial_secretary',
            ]);

        $newFs->refresh();

        // Verify can access payment form
        $this->actingAs($newFs)
            ->get(route('payments.create', $this->member))
            ->assertOk();
    });

    it('newly assigned financial secretary can record payments', function () {
        $newFs = User::factory()->member()->create(['family_id' => $this->family->id]);

        // Assign FS role
        $this->actingAs($this->admin)
            ->put("/members/{$newFs->id}", [
                'name' => $newFs->name,
                'email' => $newFs->email,
                'category' => MemberCategory::Employed->value,
                'role' => 'financial_secretary',
            ]);

        $newFs->refresh();

        // Verify can record payment
        $this->actingAs($newFs)
            ->post(route('payments.store'), [
                'member_id' => $this->member->id,
                'amount' => 1000,
                'paid_at' => now()->toDateString(),
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('payments', [
            'amount' => 1000,
            'recorded_by' => $newFs->id,
        ]);
    });

    it('demoted financial secretary cannot access payment form', function () {
        $fs = User::factory()->financialSecretary()->create(['family_id' => $this->family->id]);

        // Verify can access before demotion
        $this->actingAs($fs)
            ->get(route('payments.create', $this->member))
            ->assertOk();

        // Demote to member
        $this->actingAs($this->admin)
            ->put("/members/{$fs->id}", [
                'name' => $fs->name,
                'email' => $fs->email,
                'category' => MemberCategory::Employed->value,
                'role' => 'member',
            ]);

        $fs->refresh();

        // Verify cannot access after demotion
        $this->actingAs($fs)
            ->get(route('payments.create', $this->member))
            ->assertForbidden();
    });

    it('demoted financial secretary cannot record payments', function () {
        $fs = User::factory()->financialSecretary()->create(['family_id' => $this->family->id]);

        // Demote to member
        $this->actingAs($this->admin)
            ->put("/members/{$fs->id}", [
                'name' => $fs->name,
                'email' => $fs->email,
                'category' => MemberCategory::Employed->value,
                'role' => 'member',
            ]);

        $fs->refresh();

        // Verify cannot record payment
        $this->actingAs($fs)
            ->post(route('payments.store'), [
                'member_id' => $this->member->id,
                'amount' => 1000,
                'paid_at' => now()->toDateString(),
            ])
            ->assertForbidden();
    });

    it('newly promoted admin can manage members', function () {
        $newAdmin = User::factory()->member()->create(['family_id' => $this->family->id]);

        // Promote to admin
        $this->actingAs($this->admin)
            ->put("/members/{$newAdmin->id}", [
                'name' => $newAdmin->name,
                'email' => $newAdmin->email,
                'category' => MemberCategory::Employed->value,
                'role' => 'admin',
            ]);

        $newAdmin->refresh();

        // Verify can access member creation
        $this->actingAs($newAdmin)
            ->get(route('members.create'))
            ->assertOk();
    });

    it('newly promoted admin can assign roles', function () {
        $newAdmin = User::factory()->member()->create(['family_id' => $this->family->id]);
        $targetMember = User::factory()->member()->create(['family_id' => $this->family->id]);

        // Promote to admin
        $this->actingAs($this->admin)
            ->put("/members/{$newAdmin->id}", [
                'name' => $newAdmin->name,
                'email' => $newAdmin->email,
                'category' => MemberCategory::Employed->value,
                'role' => 'admin',
            ]);

        $newAdmin->refresh();

        // Verify can assign role
        $this->actingAs($newAdmin)
            ->put("/members/{$targetMember->id}", [
                'name' => $targetMember->name,
                'email' => $targetMember->email,
                'category' => MemberCategory::Employed->value,
                'role' => 'financial_secretary',
            ])
            ->assertRedirect();

        $targetMember->refresh();
        expect($targetMember->role)->toBe(Role::FinancialSecretary);
    });

    it('demoted admin cannot manage members', function () {
        // Create another admin with a category
        $anotherAdmin = User::factory()->admin()->employed()->create(['family_id' => $this->family->id]);

        // Demote to member
        $this->actingAs($this->admin)
            ->put("/members/{$anotherAdmin->id}", [
                'name' => $anotherAdmin->name,
                'email' => $anotherAdmin->email,
                'category' => MemberCategory::Employed->value,
                'role' => 'member',
            ]);

        $anotherAdmin->refresh();

        // Verify cannot access member creation
        $this->actingAs($anotherAdmin)
            ->get(route('members.create'))
            ->assertForbidden();
    });
});
