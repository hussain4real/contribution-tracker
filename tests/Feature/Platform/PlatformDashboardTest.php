<?php

declare(strict_types=1);

use App\Filament\Pages\Dashboard;
use App\Filament\Widgets\PlatformStatsOverview;
use App\Filament\Widgets\RecentFamilies;
use App\Filament\Widgets\RecentPayments;
use App\Models\Contribution;
use App\Models\Expense;
use App\Models\Family;
use App\Models\Payment;
use App\Models\User;
use Livewire\Livewire;

describe('Platform Dashboard', function () {
    it('allows super admin to access the Filament dashboard', function () {
        $family = Family::factory()->create();
        $superAdmin = User::factory()->admin()->superAdmin()->create(['family_id' => $family->id]);

        $this->actingAs($superAdmin)
            ->get(Dashboard::getUrl())
            ->assertOk()
            ->assertSee('Platform Overview');
    });

    it('denies access to non-super-admin users', function () {
        $family = Family::factory()->create();
        $admin = User::factory()->admin()->create(['family_id' => $family->id]);

        $this->actingAs($admin)
            ->get(Dashboard::getUrl())
            ->assertForbidden();
    });

    it('denies access to regular members', function () {
        $family = Family::factory()->create();
        $member = User::factory()->member()->create(['family_id' => $family->id]);

        $this->actingAs($member)
            ->get(Dashboard::getUrl())
            ->assertForbidden();
    });

    it('renders platform statistics in the dashboard widget', function () {
        $family1 = Family::factory()->create();
        $family2 = Family::factory()->create();
        $superAdmin = User::factory()->admin()->superAdmin()->create(['family_id' => $family1->id]);

        $member1 = User::factory()->member()->employed()->create(['family_id' => $family1->id]);
        User::factory()->member()->student()->create(['family_id' => $family2->id]);
        User::factory()->member()->create([
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

        $this->actingAs($superAdmin);

        Livewire::test(PlatformStatsOverview::class)
            ->assertOk()
            ->assertSee('Total families')
            ->assertSee('2')
            ->assertSee('Total users')
            ->assertSee('4')
            ->assertSee('Active users')
            ->assertSee('3')
            ->assertSee('Archived users')
            ->assertSee('1')
            ->assertSee('Total contributions')
            ->assertSee('Total payments')
            ->assertSee('₦4,000')
            ->assertSee('Total expenses')
            ->assertSee('₦1,500');
    });

    it('renders recent families ordered by latest', function () {
        $family1 = Family::factory()->create(['created_at' => now()->subDay()]);
        $family2 = Family::factory()->create(['created_at' => now()]);
        $superAdmin = User::factory()->admin()->superAdmin()->create(['family_id' => $family1->id]);

        $this->actingAs($superAdmin);

        $component = Livewire::test(RecentFamilies::class);

        $component->assertOk();
        $component->assertCanSeeTableRecords([$family2, $family1], inOrder: true);
    });

    it('renders recent payments', function () {
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

        $this->actingAs($superAdmin);

        Livewire::test(RecentPayments::class)
            ->assertOk()
            ->assertSee($member->name)
            ->assertSee('4,000');
    });

    it('tracks new families created this month', function () {
        $oldFamily = Family::factory()->create(['created_at' => now()->subMonth()]);
        Family::factory()->create(['created_at' => now()]);
        $superAdmin = User::factory()->admin()->superAdmin()->create(['family_id' => $oldFamily->id]);

        $this->actingAs($superAdmin);

        Livewire::test(PlatformStatsOverview::class)
            ->assertOk()
            ->assertSee('New families this month')
            ->assertSee('1');
    });
});
