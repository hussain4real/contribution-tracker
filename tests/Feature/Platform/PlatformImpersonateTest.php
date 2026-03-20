<?php

use App\Models\Family;
use App\Models\User;

describe('Platform Impersonate Users', function () {
    it('allows super admin to impersonate a user', function () {
        $family = Family::factory()->create();
        $superAdmin = User::factory()->admin()->superAdmin()->create(['family_id' => $family->id]);
        $member = User::factory()->member()->create(['family_id' => $family->id]);

        $this->actingAs($superAdmin)
            ->post("/platform/users/{$member->id}/impersonate")
            ->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($member);
    });

    it('stores original user id in session during impersonation', function () {
        $family = Family::factory()->create();
        $superAdmin = User::factory()->admin()->superAdmin()->create(['family_id' => $family->id]);
        $member = User::factory()->member()->create(['family_id' => $family->id]);

        $this->actingAs($superAdmin)
            ->post("/platform/users/{$member->id}/impersonate")
            ->assertSessionHas('impersonating_from', $superAdmin->id);
    });

    it('prevents impersonating another super admin', function () {
        $family = Family::factory()->create();
        $superAdmin1 = User::factory()->admin()->superAdmin()->create(['family_id' => $family->id]);
        $superAdmin2 = User::factory()->admin()->superAdmin()->create(['family_id' => $family->id]);

        $this->actingAs($superAdmin1)
            ->post("/platform/users/{$superAdmin2->id}/impersonate")
            ->assertRedirect()
            ->assertSessionHas('error', 'Cannot impersonate another super admin.');

        $this->assertAuthenticatedAs($superAdmin1);
    });

    it('allows stopping impersonation and returning to original user', function () {
        $family = Family::factory()->create();
        $superAdmin = User::factory()->admin()->superAdmin()->create(['family_id' => $family->id]);
        $member = User::factory()->member()->create(['family_id' => $family->id]);

        // Start impersonating
        $this->actingAs($superAdmin)
            ->post("/platform/users/{$member->id}/impersonate");

        // Stop impersonating
        $this->post('/platform/stop-impersonating')
            ->assertRedirect(route('platform.dashboard'));

        $this->assertAuthenticatedAs($superAdmin);
    });

    it('denies non-super-admin from impersonating', function () {
        $family = Family::factory()->create();
        $admin = User::factory()->admin()->create(['family_id' => $family->id]);
        $member = User::factory()->member()->create(['family_id' => $family->id]);

        $this->actingAs($admin)
            ->post("/platform/users/{$member->id}/impersonate")
            ->assertForbidden();
    });

    it('shares impersonation state via Inertia', function () {
        $family = Family::factory()->create();
        $superAdmin = User::factory()->admin()->superAdmin()->create(['family_id' => $family->id]);
        $member = User::factory()->member()->create(['family_id' => $family->id]);

        // Start impersonating
        $this->actingAs($superAdmin)
            ->post("/platform/users/{$member->id}/impersonate");

        // Check that the shared prop is set
        $this->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('impersonating', true)
            );
    });

    it('flashes success when starting impersonation', function () {
        $family = Family::factory()->create();
        $superAdmin = User::factory()->admin()->superAdmin()->create(['family_id' => $family->id]);
        $member = User::factory()->member()->create([
            'family_id' => $family->id,
            'name' => 'John Doe',
        ]);

        $this->actingAs($superAdmin)
            ->post("/platform/users/{$member->id}/impersonate")
            ->assertSessionHas('success', 'Now impersonating John Doe.');
    });
});
