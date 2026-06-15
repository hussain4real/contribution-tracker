<?php

declare(strict_types=1);

use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Resources\Users\Pages\ViewUser;
use App\Filament\Resources\Users\UserResource;
use App\Models\Family;
use App\Models\User;
use Livewire\Livewire;

describe('Platform Users', function () {
    it('allows super admin to view the Filament users list', function () {
        $family = Family::factory()->create();
        $superAdmin = User::factory()->admin()->superAdmin()->create(['family_id' => $family->id]);

        $this->actingAs($superAdmin)
            ->get(UserResource::getUrl())
            ->assertOk()
            ->assertSee('Users');
    });

    it('denies non-super-admin access to users list', function () {
        $family = Family::factory()->create();
        $admin = User::factory()->admin()->create(['family_id' => $family->id]);

        $this->actingAs($admin)
            ->get(UserResource::getUrl())
            ->assertForbidden();
    });

    it('denies regular member access to users list', function () {
        $family = Family::factory()->create();
        $member = User::factory()->member()->create(['family_id' => $family->id]);

        $this->actingAs($member)
            ->get(UserResource::getUrl())
            ->assertForbidden();
    });

    it('renders users from multiple families in the table', function () {
        $family1 = Family::factory()->create();
        $family2 = Family::factory()->create();
        $superAdmin = User::factory()->admin()->superAdmin()->create(['family_id' => $family1->id]);
        $member = User::factory()->member()->employed()->create([
            'family_id' => $family2->id,
            'name' => 'Test Member',
            'email' => 'test@example.com',
        ]);

        $this->actingAs($superAdmin);

        $component = Livewire::test(ListUsers::class);

        $component->assertOk();
        $component->assertCanSeeTableRecords([$superAdmin, $member]);
        $component->assertSee('Test Member');
        $component->assertSee('test@example.com');
        $component->assertSee($family2->name);
    });

    it('filters archived users by active status', function () {
        $family = Family::factory()->create();
        $superAdmin = User::factory()->admin()->superAdmin()->create(['family_id' => $family->id]);
        $archivedUser = User::factory()->member()->create([
            'family_id' => $family->id,
            'archived_at' => now(),
        ]);

        $this->actingAs($superAdmin);

        Livewire::test(ListUsers::class)
            ->filterTable('active', false)
            ->assertCanSeeTableRecords([$archivedUser])
            ->assertCanNotSeeTableRecords([$superAdmin]);
    });

    it('allows super admin to view a user detail page', function () {
        $family = Family::factory()->create();
        $superAdmin = User::factory()->admin()->superAdmin()->create(['family_id' => $family->id]);
        $member = User::factory()->member()->create([
            'family_id' => $family->id,
            'name' => 'Detail Member',
        ]);

        $this->actingAs($superAdmin)
            ->get(UserResource::getUrl('view', ['record' => $member]))
            ->assertOk()
            ->assertSee('Detail Member')
            ->assertSee($member->email);
    });

    it('hides impersonation when viewing another super admin', function () {
        $family = Family::factory()->create();
        $superAdmin = User::factory()->admin()->superAdmin()->create(['family_id' => $family->id]);
        $otherSuperAdmin = User::factory()->admin()->superAdmin()->create(['family_id' => $family->id]);

        $this->actingAs($superAdmin);

        Livewire::test(ViewUser::class, ['record' => $otherSuperAdmin->getRouteKey()])
            ->assertActionHidden('impersonate');
    });

    it('redirects unauthenticated visitors away from the users list', function () {
        $this->get(UserResource::getUrl())
            ->assertRedirect();
    });
});
