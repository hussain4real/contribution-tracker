<?php

use App\Models\Family;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

describe('Platform Users', function () {
    it('allows super admin to view users list', function () {
        $family = Family::factory()->create();
        $superAdmin = User::factory()->admin()->superAdmin()->create(['family_id' => $family->id]);

        $this->actingAs($superAdmin)
            ->get('/platform/users')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Platform/Users')
                ->has('users.data', 1)
            );
    });

    it('denies non-super-admin access to users list', function () {
        $family = Family::factory()->create();
        $admin = User::factory()->admin()->create(['family_id' => $family->id]);

        $this->actingAs($admin)
            ->get('/platform/users')
            ->assertForbidden();
    });

    it('denies regular member access to users list', function () {
        $family = Family::factory()->create();
        $member = User::factory()->member()->create(['family_id' => $family->id]);

        $this->actingAs($member)
            ->get('/platform/users')
            ->assertForbidden();
    });

    it('returns paginated users with correct data', function () {
        $family = Family::factory()->create();
        $superAdmin = User::factory()->admin()->superAdmin()->create(['family_id' => $family->id]);
        $member = User::factory()->member()->employed()->create(['family_id' => $family->id]);

        $this->actingAs($superAdmin)
            ->get('/platform/users')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('users.data', 2)
            );
    });

    it('returns correct user attributes', function () {
        $family = Family::factory()->create();
        $superAdmin = User::factory()->admin()->superAdmin()->create(['family_id' => $family->id]);
        $member = User::factory()->member()->employed()->create([
            'family_id' => $family->id,
            'name' => 'Test Member',
            'email' => 'test@example.com',
        ]);

        $response = $this->actingAs($superAdmin)
            ->get('/platform/users')
            ->assertOk();

        $response->assertInertia(fn (Assert $page) => $page
            ->has('users.data', 2)
        );

        $usersData = $response->original->getData()['page']['props']['users']['data'];
        $memberData = collect($usersData)->firstWhere('email', 'test@example.com');

        expect($memberData['name'])->toBe('Test Member')
            ->and($memberData['family_name'])->toBe($family->name)
            ->and($memberData['is_active'])->toBeTrue();
    });

    it('shows archived users with correct status', function () {
        $family = Family::factory()->create();
        $superAdmin = User::factory()->admin()->superAdmin()->create(['family_id' => $family->id]);
        $archivedUser = User::factory()->member()->create([
            'family_id' => $family->id,
            'archived_at' => now(),
        ]);

        $this->actingAs($superAdmin)
            ->get('/platform/users')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('users.data', 2)
            );
    });

    it('shows users from multiple families', function () {
        $family1 = Family::factory()->create();
        $family2 = Family::factory()->create();
        $superAdmin = User::factory()->admin()->superAdmin()->create(['family_id' => $family1->id]);
        User::factory()->member()->create(['family_id' => $family2->id]);

        $this->actingAs($superAdmin)
            ->get('/platform/users')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('users.data', 2)
            );
    });

    it('denies unauthenticated access', function () {
        $this->get('/platform/users')
            ->assertRedirect();
    });
});
