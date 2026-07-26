<?php

declare(strict_types=1);

use App\Filament\Pages\Dashboard;
use App\Models\Family;
use App\Models\User;

describe('Platform family suspension access', function () {
    it('blocks suspended family members from accessing the app', function () {
        $family = Family::factory()->suspended()->create();
        $member = User::factory()->member()->create(['family_id' => $family->id]);

        $this->actingAs($member)
            ->get('/dashboard')
            ->assertForbidden();
    });

    it('allows super admin to access the platform even if their family is suspended', function () {
        $family = Family::factory()->suspended()->create();
        $superAdmin = User::factory()->admin()->superAdmin()->create(['family_id' => $family->id]);

        $this->actingAs($superAdmin)
            ->get(Dashboard::getUrl())
            ->assertOk();
    });
});
