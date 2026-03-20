<?php

use App\Models\Contribution;
use App\Models\Expense;
use App\Models\Family;
use App\Models\Payment;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

describe('Platform Dashboard', function () {
    it('allows super admin to access the dashboard', function () {
        $family = Family::factory()->create();
        $superAdmin = User::factory()->admin()->superAdmin()->create(['family_id' => $family->id]);

        $this->actingAs($superAdmin)
            ->get('/platform')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Platform/Dashboard')
                ->has('stats')
                ->has('recent_families')
                ->has('recent_payments')
            );
    });

    it('denies access to non-super-admin users', function () {
        $family = Family::factory()->create();
        $admin = User::factory()->admin()->create(['family_id' => $family->id]);

        $this->actingAs($admin)
            ->get('/platform')
            ->assertForbidden();
    });

    it('denies access to regular members', function () {
        $family = Family::factory()->create();
        $member = User::factory()->member()->create(['family_id' => $family->id]);

        $this->actingAs($member)
            ->get('/platform')
            ->assertForbidden();
    });

    it('returns correct platform statistics', function () {
        $family1 = Family::factory()->create();
        $family2 = Family::factory()->create();
        $superAdmin = User::factory()->admin()->superAdmin()->create(['family_id' => $family1->id]);

        $member1 = User::factory()->member()->employed()->create(['family_id' => $family1->id]);
        $member2 = User::factory()->member()->student()->create(['family_id' => $family2->id]);
        $archivedUser = User::factory()->member()->create([
            'family_id' => $family1->id,
            'archived_at' => now(),
        ]);

        $contribution = Contribution::factory()
            ->forUser($member1)
            ->currentMonth()
            ->employed()
            ->create();

        Payment::factory()
            ->forContribution($contribution)
            ->recordedBy($superAdmin)
            ->create(['amount' => 4000]);

        Expense::factory()->create([
            'family_id' => $family1->id,
            'amount' => 1500,
            'recorded_by' => $superAdmin->id,
        ]);

        $this->actingAs($superAdmin)
            ->get('/platform')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Platform/Dashboard')
                ->where('stats.total_families', 2)
                ->where('stats.total_users', 4)
                ->where('stats.active_users', 3)
                ->where('stats.archived_users', 1)
                ->where('stats.total_payments', 4000)
                ->where('stats.total_expenses', 1500)
                ->where('stats.total_contributions', 1)
            );
    });

    it('returns recent families ordered by latest', function () {
        $family1 = Family::factory()->create(['created_at' => now()->subDay()]);
        $family2 = Family::factory()->create(['created_at' => now()]);
        $superAdmin = User::factory()->admin()->superAdmin()->create(['family_id' => $family1->id]);

        $this->actingAs($superAdmin)
            ->get('/platform')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('recent_families', 2)
                ->where('recent_families.0.name', $family2->name)
                ->where('recent_families.1.name', $family1->name)
            );
    });

    it('returns recent payments', function () {
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
            ->create(['amount' => 4000]);

        $this->actingAs($superAdmin)
            ->get('/platform')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('recent_payments', 1)
                ->where('recent_payments.0.amount', 4000)
                ->where('recent_payments.0.member_name', $member->name)
            );
    });

    it('tracks new families created this month', function () {
        $oldFamily = Family::factory()->create(['created_at' => now()->subMonth()]);
        $newFamily = Family::factory()->create(['created_at' => now()]);
        $superAdmin = User::factory()->admin()->superAdmin()->create(['family_id' => $oldFamily->id]);

        $this->actingAs($superAdmin)
            ->get('/platform')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('stats.new_families_this_month', 1)
            );
    });
});
