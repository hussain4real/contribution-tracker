<?php

declare(strict_types=1);

use App\Filament\Resources\Families\FamilyResource;
use App\Filament\Resources\Families\Pages\ListFamilies;
use App\Filament\Resources\Families\Pages\ViewFamily;
use App\Models\Contribution;
use App\Models\Family;
use App\Models\Payment;
use App\Models\User;
use App\Support\PlatformFamilySummary;
use Livewire\Livewire;

describe('Platform Families', function () {
    it('allows super admin to view the Filament families list', function () {
        $family = Family::factory()->create();
        $superAdmin = User::factory()->admin()->superAdmin()->create(['family_id' => $family->id]);

        $this->actingAs($superAdmin)
            ->get(FamilyResource::getUrl())
            ->assertOk()
            ->assertSee('Families');
    });

    it('denies non-super-admin access to families list', function () {
        $family = Family::factory()->create();
        $admin = User::factory()->admin()->create(['family_id' => $family->id]);

        $this->actingAs($admin)
            ->get(FamilyResource::getUrl())
            ->assertForbidden();
    });

    it('renders families with owner and member count in the table', function () {
        $family = Family::factory()->create();
        $owner = User::factory()->admin()->superAdmin()->create(['family_id' => $family->id]);
        $family->update(['created_by' => $owner->id]);

        User::factory()->member()->count(2)->create(['family_id' => $family->id]);

        $this->actingAs($owner);

        $component = Livewire::test(ListFamilies::class);

        $component->assertOk();
        $component->assertCanSeeTableRecords([$family]);
        $component->assertSee($family->name);
        $component->assertSee($owner->name);
        $component->assertSee('3');
    });

    it('allows super admin to view family detail', function () {
        $family = Family::factory()->create();
        $superAdmin = User::factory()->admin()->superAdmin()->create(['family_id' => $family->id]);

        $this->actingAs($superAdmin)
            ->get(FamilyResource::getUrl('view', ['record' => $family]))
            ->assertOk()
            ->assertSee($family->name)
            ->assertSee($family->currency)
            ->assertSee('Financial summary');
    });

    it('denies non-super-admin access to family detail', function () {
        $family = Family::factory()->create();
        $admin = User::factory()->admin()->create(['family_id' => $family->id]);

        $this->actingAs($admin)
            ->get(FamilyResource::getUrl('view', ['record' => $family]))
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

        expect(PlatformFamilySummary::for($family))->toMatchArray([
            'total_contributions' => 1,
            'total_collected' => 2000,
            'total_expected' => 4000,
            'collection_rate' => 50.0,
            'active_members' => 2,
            'archived_members' => 0,
        ]);
    });

    it('summarizes active and archived members', function () {
        $family = Family::factory()->create();
        User::factory()->admin()->superAdmin()->create(['family_id' => $family->id]);
        User::factory()->member()->create(['family_id' => $family->id]);
        User::factory()->member()->create([
            'family_id' => $family->id,
            'archived_at' => now(),
        ]);

        expect(PlatformFamilySummary::for($family))->toMatchArray([
            'active_members' => 2,
            'archived_members' => 1,
        ]);
    });

    it('suspends and unsuspends a family from the view page action', function () {
        $family = Family::factory()->create(['name' => 'Test Family']);
        $superAdmin = User::factory()->admin()->superAdmin()->create(['family_id' => $family->id]);

        $this->actingAs($superAdmin);

        Livewire::test(ViewFamily::class, ['record' => $family->getRouteKey()])
            ->callAction('suspend')
            ->assertNotified('Family "Test Family" has been suspended.');

        expect($family->refresh()->isSuspended())->toBeTrue();

        Livewire::test(ViewFamily::class, ['record' => $family->getRouteKey()])
            ->callAction('unsuspend')
            ->assertNotified('Family "Test Family" has been unsuspended.');

        expect($family->refresh()->isSuspended())->toBeFalse();
    });

    it('shows suspended families in the table', function () {
        $family = Family::factory()->suspended()->create();
        $superAdmin = User::factory()->admin()->superAdmin()->create(['family_id' => $family->id]);

        $this->actingAs($superAdmin);

        Livewire::test(ListFamilies::class)
            ->filterTable('suspended', true)
            ->assertCanSeeTableRecords([$family]);
    });
});
