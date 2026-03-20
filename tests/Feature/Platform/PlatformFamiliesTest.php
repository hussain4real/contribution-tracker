<?php

use App\Models\Contribution;
use App\Models\Family;
use App\Models\Payment;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

describe('Platform Families', function () {
    it('allows super admin to view families list', function () {
        $family = Family::factory()->create();
        $superAdmin = User::factory()->admin()->superAdmin()->create(['family_id' => $family->id]);

        $this->actingAs($superAdmin)
            ->get('/platform/families')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Platform/Families')
                ->has('families.data', 1)
            );
    });

    it('denies non-super-admin access to families list', function () {
        $family = Family::factory()->create();
        $admin = User::factory()->admin()->create(['family_id' => $family->id]);

        $this->actingAs($admin)
            ->get('/platform/families')
            ->assertForbidden();
    });

    it('returns paginated families with correct data', function () {
        $family = Family::factory()->create();
        $owner = User::factory()->admin()->superAdmin()->create(['family_id' => $family->id]);
        $family->update(['created_by' => $owner->id]);

        User::factory()->member()->create(['family_id' => $family->id]);
        User::factory()->member()->create(['family_id' => $family->id]);

        $this->actingAs($owner)
            ->get('/platform/families')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('families.data', 1)
                ->where('families.data.0.name', $family->name)
                ->where('families.data.0.members_count', 3)
                ->where('families.data.0.owner_name', $owner->name)
                ->where('families.data.0.currency', $family->currency)
            );
    });

    it('allows super admin to view family detail', function () {
        $family = Family::factory()->create();
        $superAdmin = User::factory()->admin()->superAdmin()->create(['family_id' => $family->id]);

        $this->actingAs($superAdmin)
            ->get("/platform/families/{$family->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Platform/FamilyDetail')
                ->has('family')
                ->where('family.name', $family->name)
                ->where('family.currency', $family->currency)
                ->has('family.financial_summary')
            );
    });

    it('denies non-super-admin access to family detail', function () {
        $family = Family::factory()->create();
        $admin = User::factory()->admin()->create(['family_id' => $family->id]);

        $this->actingAs($admin)
            ->get("/platform/families/{$family->id}")
            ->assertForbidden();
    });

    it('returns financial summary for a family', function () {
        $family = Family::factory()->create();
        $superAdmin = User::factory()->admin()->superAdmin()->create(['family_id' => $family->id]);
        $member = User::factory()->member()->employed()->create(['family_id' => $family->id]);

        $contribution = Contribution::factory()
            ->forUser($member)
            ->currentMonth()
            ->employed()
            ->create();

        Payment::factory()
            ->forContribution($contribution)
            ->recordedBy($superAdmin)
            ->create(['amount' => 2000]);

        $this->actingAs($superAdmin)
            ->get("/platform/families/{$family->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('family.financial_summary.total_contributions', 1)
                ->where('family.financial_summary.total_collected', 2000)
                ->where('family.financial_summary.total_expected', 4000)
                ->where('family.financial_summary.collection_rate', 50)
                ->where('family.financial_summary.active_members', 2)
                ->where('family.financial_summary.archived_members', 0)
            );
    });

    it('shows member status in family detail', function () {
        $family = Family::factory()->create();
        $superAdmin = User::factory()->admin()->superAdmin()->create(['family_id' => $family->id]);
        $activeMember = User::factory()->member()->create(['family_id' => $family->id]);
        $archivedMember = User::factory()->member()->create([
            'family_id' => $family->id,
            'archived_at' => now(),
        ]);

        $this->actingAs($superAdmin)
            ->get("/platform/families/{$family->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('family.members', 3)
                ->where('family.financial_summary.active_members', 2)
                ->where('family.financial_summary.archived_members', 1)
            );
    });
});
