<?php

use App\Features\AiAssistant;
use App\Models\Family;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Pennant\Feature;

describe('Platform Feature Flags', function () {
    it('allows super admin to view the feature flags page', function () {
        $family = Family::factory()->create();
        $superAdmin = User::factory()->admin()->superAdmin()->create(['family_id' => $family->id]);

        $this->actingAs($superAdmin)
            ->get('/platform/feature-flags')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Platform/FeatureFlags')
                ->has('features')
                ->has('users')
            );
    });

    it('denies access to non-super-admin users', function () {
        $family = Family::factory()->create();
        $admin = User::factory()->admin()->create(['family_id' => $family->id]);

        $this->actingAs($admin)
            ->get('/platform/feature-flags')
            ->assertForbidden();
    });

    it('denies access to regular members', function () {
        $family = Family::factory()->create();
        $member = User::factory()->member()->create(['family_id' => $family->id]);

        $this->actingAs($member)
            ->get('/platform/feature-flags')
            ->assertForbidden();
    });

    it('can activate a feature for everyone', function () {
        $family = Family::factory()->create();
        $superAdmin = User::factory()->admin()->superAdmin()->create(['family_id' => $family->id]);
        $member = User::factory()->member()->create(['family_id' => $family->id]);

        $this->actingAs($superAdmin)
            ->post('/platform/feature-flags/ai-assistant/activate-all')
            ->assertRedirect();

        Feature::flushCache();

        expect(Feature::for($member)->active(AiAssistant::class))->toBeTrue();
    });

    it('can deactivate a feature for everyone', function () {
        $family = Family::factory()->create();
        $superAdmin = User::factory()->admin()->superAdmin()->create(['family_id' => $family->id]);
        $member = User::factory()->member()->create(['family_id' => $family->id]);

        // Set up global activation first
        DB::table('features')->insert([
            'name' => AiAssistant::class,
            'scope' => '',
            'value' => 'true',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($superAdmin)
            ->post('/platform/feature-flags/ai-assistant/deactivate-all')
            ->assertRedirect();

        Feature::flushCache();

        expect(Feature::for($member)->active(AiAssistant::class))->toBeFalse();
    });

    it('can activate a feature for a specific user', function () {
        $family = Family::factory()->create();
        $superAdmin = User::factory()->admin()->superAdmin()->create(['family_id' => $family->id]);
        $member = User::factory()->member()->create(['family_id' => $family->id]);

        $this->actingAs($superAdmin)
            ->post('/platform/feature-flags/ai-assistant/activate', [
                'user_id' => $member->id,
            ])
            ->assertRedirect();

        expect(Feature::for($member)->active(AiAssistant::class))->toBeTrue();
    });

    it('can deactivate a feature for a specific user', function () {
        $family = Family::factory()->create();
        $superAdmin = User::factory()->admin()->superAdmin()->create(['family_id' => $family->id]);
        $member = User::factory()->member()->create(['family_id' => $family->id]);

        Feature::for($member)->activate(AiAssistant::class);

        $this->actingAs($superAdmin)
            ->post('/platform/feature-flags/ai-assistant/deactivate', [
                'user_id' => $member->id,
            ])
            ->assertRedirect();

        Feature::flushCache();

        expect(Feature::for($member)->active(AiAssistant::class))->toBeFalse();
    });

    it('returns 404 for unknown feature keys', function () {
        $family = Family::factory()->create();
        $superAdmin = User::factory()->admin()->superAdmin()->create(['family_id' => $family->id]);

        $this->actingAs($superAdmin)
            ->post('/platform/feature-flags/unknown-feature/activate-all')
            ->assertNotFound();
    });

    it('validates user_id when activating for a specific user', function () {
        $family = Family::factory()->create();
        $superAdmin = User::factory()->admin()->superAdmin()->create(['family_id' => $family->id]);

        $this->actingAs($superAdmin)
            ->post('/platform/feature-flags/ai-assistant/activate', [
                'user_id' => 99999,
            ])
            ->assertSessionHasErrors('user_id');
    });
});

describe('AI Assistant Feature Flag Route Gating', function () {
    it('allows access to AI routes when feature is active', function () {
        $family = Family::factory()->create();
        $user = User::factory()->admin()->create(['family_id' => $family->id]);

        Feature::for($user)->activate(AiAssistant::class);

        $this->actingAs($user)
            ->get('/ai')
            ->assertOk();
    });

    it('denies access to AI routes when feature is inactive', function () {
        $family = Family::factory()->create();
        $user = User::factory()->admin()->create(['family_id' => $family->id]);

        $this->actingAs($user)
            ->get('/ai')
            ->assertStatus(400);
    });

    it('grants access when feature is activated for everyone', function () {
        $family = Family::factory()->create();
        $user = User::factory()->member()->create(['family_id' => $family->id]);

        // Insert global activation record (same approach as the controller)
        DB::table('features')->insert([
            'name' => AiAssistant::class,
            'scope' => '',
            'value' => 'true',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        Feature::flushCache();

        $this->actingAs($user)
            ->get('/ai')
            ->assertOk();
    });

    it('shares ai_assistant feature flag as true with frontend when active', function () {
        $family = Family::factory()->create();
        $user = User::factory()->member()->create(['family_id' => $family->id]);

        Feature::for($user)->activate(AiAssistant::class);

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('featureFlags.ai_assistant', true)
            );
    });

    it('shares ai_assistant feature flag as false with frontend when inactive', function () {
        $family = Family::factory()->create();
        $user = User::factory()->member()->create(['family_id' => $family->id]);

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('featureFlags.ai_assistant', false)
            );
    });
});
