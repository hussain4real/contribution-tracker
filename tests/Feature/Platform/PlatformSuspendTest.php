<?php

use App\Models\Family;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

describe('Platform Suspend/Unsuspend Families', function () {
    it('allows super admin to suspend a family', function () {
        $family = Family::factory()->create();
        $superAdmin = User::factory()->admin()->superAdmin()->create(['family_id' => $family->id]);

        $this->actingAs($superAdmin)
            ->post("/platform/families/{$family->id}/suspend")
            ->assertRedirect();

        $family->refresh();
        expect($family->isSuspended())->toBeTrue()
            ->and($family->suspended_at)->not->toBeNull();
    });

    it('allows super admin to unsuspend a family', function () {
        $family = Family::factory()->suspended()->create();
        $superAdmin = User::factory()->admin()->superAdmin()->create(['family_id' => $family->id]);

        $this->actingAs($superAdmin)
            ->post("/platform/families/{$family->id}/unsuspend")
            ->assertRedirect();

        $family->refresh();
        expect($family->isSuspended())->toBeFalse()
            ->and($family->suspended_at)->toBeNull();
    });

    it('denies non-super-admin from suspending a family', function () {
        $family = Family::factory()->create();
        $admin = User::factory()->admin()->create(['family_id' => $family->id]);

        $this->actingAs($admin)
            ->post("/platform/families/{$family->id}/suspend")
            ->assertForbidden();

        $family->refresh();
        expect($family->isSuspended())->toBeFalse();
    });

    it('shows suspended_at in family detail', function () {
        $family = Family::factory()->suspended()->create();
        $superAdmin = User::factory()->admin()->superAdmin()->create(['family_id' => $family->id]);

        $this->actingAs($superAdmin)
            ->get("/platform/families/{$family->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Platform/FamilyDetail')
                ->where('family.suspended_at', $family->suspended_at->toDateString())
            );
    });

    it('shows suspension status in families list', function () {
        $family = Family::factory()->suspended()->create();
        $superAdmin = User::factory()->admin()->superAdmin()->create(['family_id' => $family->id]);

        $this->actingAs($superAdmin)
            ->get('/platform/families')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Platform/Families')
                ->where('families.data.0.is_suspended', true)
            );
    });

    it('blocks suspended family members from accessing the app', function () {
        $family = Family::factory()->suspended()->create();
        $member = User::factory()->member()->create(['family_id' => $family->id]);

        $this->actingAs($member)
            ->get('/dashboard')
            ->assertForbidden();
    });

    it('allows super admin to access even if their family is suspended', function () {
        $family = Family::factory()->suspended()->create();
        $superAdmin = User::factory()->admin()->superAdmin()->create(['family_id' => $family->id]);

        $this->actingAs($superAdmin)
            ->get('/platform')
            ->assertOk();
    });

    it('flashes success message when suspending', function () {
        $family = Family::factory()->create(['name' => 'Test Family']);
        $superAdmin = User::factory()->admin()->superAdmin()->create(['family_id' => $family->id]);

        $this->actingAs($superAdmin)
            ->post("/platform/families/{$family->id}/suspend")
            ->assertSessionHas('success', 'Family "Test Family" has been suspended.');
    });

    it('flashes success message when unsuspending', function () {
        $family = Family::factory()->suspended()->create(['name' => 'Test Family']);
        $superAdmin = User::factory()->admin()->superAdmin()->create(['family_id' => $family->id]);

        $this->actingAs($superAdmin)
            ->post("/platform/families/{$family->id}/unsuspend")
            ->assertSessionHas('success', 'Family "Test Family" has been unsuspended.');
    });
});
