<?php

declare(strict_types=1);

use App\Features\AiAssistant;
use App\Filament\Pages\FeatureFlags;
use App\Models\Family;
use App\Models\User;
use App\Support\PlatformFeatureRegistry;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Pennant\Feature;
use Livewire\Livewire;

describe('Platform Feature Flags', function () {
    it('allows super admin to view the Filament feature flags page', function () {
        $family = Family::factory()->create();
        $superAdmin = User::factory()->admin()->superAdmin()->create(['family_id' => $family->id]);

        $this->actingAs($superAdmin)
            ->get(FeatureFlags::getUrl())
            ->assertOk()
            ->assertSee('Feature Flags')
            ->assertSee('AI Assistant');
    });

    it('returns activated user ids for each feature', function () {
        $family = Family::factory()->create();
        User::factory()->admin()->superAdmin()->create(['family_id' => $family->id]);
        $member = User::factory()->member()->create(['family_id' => $family->id]);

        Feature::for($member)->activate(AiAssistant::class);

        $feature = PlatformFeatureRegistry::all()[0];

        expect($feature['activated_user_ids'])->toBe([$member->id])
            ->and($feature['status'])->toBe('partial');
    });

    it('denies access to non-super-admin users', function () {
        $family = Family::factory()->create();
        $admin = User::factory()->admin()->create(['family_id' => $family->id]);

        $this->actingAs($admin)
            ->get(FeatureFlags::getUrl())
            ->assertForbidden();
    });

    it('denies access to regular members', function () {
        $family = Family::factory()->create();
        $member = User::factory()->member()->create(['family_id' => $family->id]);

        $this->actingAs($member)
            ->get(FeatureFlags::getUrl())
            ->assertForbidden();
    });

    it('can activate a feature for everyone', function () {
        $family = Family::factory()->create();
        $superAdmin = User::factory()->admin()->superAdmin()->create(['family_id' => $family->id]);
        $member = User::factory()->member()->create(['family_id' => $family->id]);

        $this->actingAs($superAdmin);

        Livewire::test(FeatureFlags::class)
            ->callAction('activateForEveryone', ['feature' => 'ai-assistant'])
            ->assertHasNoActionErrors()
            ->assertNotified('"AI Assistant" has been activated for everyone.');

        Feature::flushCache();

        expect(Feature::for($member)->active(AiAssistant::class))->toBeTrue();
    });

    it('clears stale false records when activating for everyone', function () {
        $family = Family::factory()->create();
        $superAdmin = User::factory()->admin()->superAdmin()->create(['family_id' => $family->id]);
        $member = User::factory()->member()->create(['family_id' => $family->id]);

        Feature::for($member)->deactivate(AiAssistant::class);
        Feature::flushCache();

        expect(Feature::for($member)->active(AiAssistant::class))->toBeFalse();

        $this->actingAs($superAdmin);

        Livewire::test(FeatureFlags::class)
            ->callAction('activateForEveryone', ['feature' => 'ai-assistant']);

        Feature::flushCache();

        expect(Feature::for($member)->active(AiAssistant::class))->toBeTrue()
            ->and(DB::table('features')
                ->where('name', AiAssistant::class)
                ->where('scope', '!=', '')
                ->where('value', 'false')
                ->exists())->toBeFalse();
    });

    it('can deactivate a feature for everyone', function () {
        $family = Family::factory()->create();
        $superAdmin = User::factory()->admin()->superAdmin()->create(['family_id' => $family->id]);
        $member = User::factory()->member()->create(['family_id' => $family->id]);

        DB::table('features')->insert([
            ['name' => AiAssistant::class, 'scope' => '', 'value' => 'true', 'created_at' => now(), 'updated_at' => now()],
            ['name' => AiAssistant::class, 'scope' => 'App\Models\User|'.$member->id, 'value' => 'true', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $this->actingAs($superAdmin);

        Livewire::test(FeatureFlags::class)
            ->callAction('deactivateForEveryone', ['feature' => 'ai-assistant'])
            ->assertHasNoActionErrors()
            ->assertNotified('"AI Assistant" has been deactivated for everyone.');

        Feature::flushCache();

        expect(Feature::for($member)->active(AiAssistant::class))->toBeFalse()
            ->and(DB::table('features')
                ->where('name', AiAssistant::class)
                ->where('value', 'true')
                ->exists())->toBeFalse();
    });

    it('can activate a feature for a specific user', function () {
        $family = Family::factory()->create();
        $superAdmin = User::factory()->admin()->superAdmin()->create(['family_id' => $family->id]);
        $member = User::factory()->member()->create(['family_id' => $family->id]);

        $this->actingAs($superAdmin);

        Livewire::test(FeatureFlags::class)
            ->callAction('activateForUser', [
                'feature' => 'ai-assistant',
                'user_id' => $member->id,
            ])
            ->assertHasNoActionErrors();

        expect(Feature::for($member)->active(AiAssistant::class))->toBeTrue();
    });

    it('can deactivate a feature for a specific user', function () {
        $family = Family::factory()->create();
        $superAdmin = User::factory()->admin()->superAdmin()->create(['family_id' => $family->id]);
        $member = User::factory()->member()->create(['family_id' => $family->id]);

        Feature::for($member)->activate(AiAssistant::class);

        $this->actingAs($superAdmin);

        Livewire::test(FeatureFlags::class)
            ->callAction('deactivateForUser', [
                'feature' => 'ai-assistant',
                'user_id' => $member->id,
            ])
            ->assertHasNoActionErrors();

        Feature::flushCache();

        expect(Feature::for($member)->active(AiAssistant::class))->toBeFalse();
    });

    it('validates unknown feature keys', function () {
        $family = Family::factory()->create();
        $superAdmin = User::factory()->admin()->superAdmin()->create(['family_id' => $family->id]);

        $this->actingAs($superAdmin);

        Livewire::test(FeatureFlags::class)
            ->callAction('activateForEveryone', ['feature' => 'unknown-feature'])
            ->assertHasActionErrors(['feature' => 'in']);
    });

    it('validates user_id when activating for a specific user', function () {
        $family = Family::factory()->create();
        $superAdmin = User::factory()->admin()->superAdmin()->create(['family_id' => $family->id]);

        $this->actingAs($superAdmin);

        Livewire::test(FeatureFlags::class)
            ->callAction('activateForUser', [
                'feature' => 'ai-assistant',
                'user_id' => 99999,
            ])
            ->assertHasActionErrors(['user_id']);
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
            ->assertRedirect(route('dashboard'))
            ->assertSessionHas('warning');
    });

    it('grants access when feature is activated for everyone', function () {
        $family = Family::factory()->create();
        $user = User::factory()->member()->create(['family_id' => $family->id]);

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
