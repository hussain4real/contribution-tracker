<?php

declare(strict_types=1);

use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Resources\Users\Pages\ViewUser;
use App\Filament\Resources\Users\UserResource;
use App\Models\Family;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Livewire\Livewire;

describe('Platform Impersonate Users', function () {
    it('allows super admin to impersonate a user', function () {
        $family = Family::factory()->create();
        $superAdmin = User::factory()->admin()->superAdmin()->create(['family_id' => $family->id]);
        $member = User::factory()->member()->create(['family_id' => $family->id]);

        $this->actingAs($superAdmin);

        Livewire::test(ViewUser::class, ['record' => $member->getRouteKey()])
            ->callAction('impersonate')
            ->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($member);
    });

    it('stores original user id in session during impersonation', function () {
        $family = Family::factory()->create();
        $superAdmin = User::factory()->admin()->superAdmin()->create(['family_id' => $family->id]);
        $member = User::factory()->member()->create(['family_id' => $family->id]);

        $this->actingAs($superAdmin);

        Livewire::test(ViewUser::class, ['record' => $member->getRouteKey()])
            ->callAction('impersonate');

        expect(session('impersonating_from'))->toBe($superAdmin->id);
    });

    it('prevents impersonating another super admin', function () {
        $family = Family::factory()->create();
        $superAdmin1 = User::factory()->admin()->superAdmin()->create(['family_id' => $family->id]);
        $superAdmin2 = User::factory()->admin()->superAdmin()->create(['family_id' => $family->id]);

        $this->actingAs($superAdmin1);

        Livewire::test(ViewUser::class, ['record' => $superAdmin2->getRouteKey()])
            ->assertActionHidden('impersonate');

        $this->assertAuthenticatedAs($superAdmin1);
    });

    it('allows stopping impersonation and returning to original user', function () {
        $family = Family::factory()->create();
        $superAdmin = User::factory()->admin()->superAdmin()->create(['family_id' => $family->id]);
        $member = User::factory()->member()->create(['family_id' => $family->id]);

        $this->actingAs($superAdmin);

        Livewire::test(ViewUser::class, ['record' => $member->getRouteKey()])
            ->callAction('impersonate');

        $this->post('/platform/stop-impersonating')
            ->assertRedirect('/platform');

        $this->assertAuthenticatedAs($superAdmin);
    });

    it('redirects to dashboard when stopping impersonation without an original user', function () {
        $superAdmin = User::factory()->admin()->superAdmin()->create();

        $this->actingAs($superAdmin)
            ->post('/platform/stop-impersonating')
            ->assertRedirect(route('dashboard'));
    });

    it('denies non-super-admin from reaching the impersonation action surface', function () {
        $family = Family::factory()->create();
        $admin = User::factory()->admin()->create(['family_id' => $family->id]);
        $member = User::factory()->member()->create(['family_id' => $family->id]);

        $this->actingAs($admin)
            ->get(UserResource::getUrl('view', ['record' => $member]))
            ->assertForbidden();
    });

    it('shares impersonation state via Inertia', function () {
        $family = Family::factory()->create();
        $superAdmin = User::factory()->admin()->superAdmin()->create(['family_id' => $family->id]);
        $member = User::factory()->member()->create(['family_id' => $family->id]);

        $this->actingAs($superAdmin);

        Livewire::test(ViewUser::class, ['record' => $member->getRouteKey()])
            ->callAction('impersonate');

        $this->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
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

        $this->actingAs($superAdmin);

        Livewire::test(ViewUser::class, ['record' => $member->getRouteKey()])
            ->callAction('impersonate');

        expect(session('success'))->toBe('Now impersonating John Doe.');
    });

    it('allows super admin to impersonate a user from the users table', function () {
        $family = Family::factory()->create();
        $superAdmin = User::factory()->admin()->superAdmin()->create(['family_id' => $family->id]);
        $member = User::factory()->member()->create([
            'family_id' => $family->id,
            'name' => 'Table User',
        ]);

        $this->actingAs($superAdmin);

        Livewire::test(ListUsers::class)
            ->callTableAction('impersonate', $member)
            ->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($member);

        expect(session('impersonating_from'))->toBe($superAdmin->id)
            ->and(session('success'))->toBe('Now impersonating Table User.');
    });
});
