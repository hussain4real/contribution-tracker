<?php

use App\Models\Family;
use App\Models\User;

describe('Platform CSV Exports', function () {
    it('allows super admin to export families as CSV', function () {
        $family = Family::factory()->create(['name' => 'Test Family']);
        $superAdmin = User::factory()->admin()->superAdmin()->create(['family_id' => $family->id]);

        $response = $this->actingAs($superAdmin)
            ->get('/platform/families/export');

        $response->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8')
            ->assertDownload();

        $content = $response->streamedContent();
        expect($content)->toContain('ID,Name,Slug,Currency')
            ->and($content)->toContain('Test Family');
    });

    it('allows super admin to export users as CSV', function () {
        $family = Family::factory()->create();
        $superAdmin = User::factory()->admin()->superAdmin()->create([
            'family_id' => $family->id,
            'name' => 'Admin User',
            'email' => 'admin@test.com',
        ]);
        User::factory()->member()->create([
            'family_id' => $family->id,
            'name' => 'Regular Member',
            'email' => 'member@test.com',
        ]);

        $response = $this->actingAs($superAdmin)
            ->get('/platform/users/export');

        $response->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8')
            ->assertDownload();

        $content = $response->streamedContent();
        expect($content)->toContain('ID,Name,Email,Family')
            ->and($content)->toContain('Admin User')
            ->and($content)->toContain('Regular Member');
    });

    it('denies non-super-admin access to families export', function () {
        $family = Family::factory()->create();
        $admin = User::factory()->admin()->create(['family_id' => $family->id]);

        $this->actingAs($admin)
            ->get('/platform/families/export')
            ->assertForbidden();
    });

    it('denies non-super-admin access to users export', function () {
        $family = Family::factory()->create();
        $admin = User::factory()->admin()->create(['family_id' => $family->id]);

        $this->actingAs($admin)
            ->get('/platform/users/export')
            ->assertForbidden();
    });

    it('includes suspension status in families export', function () {
        $family = Family::factory()->suspended()->create(['name' => 'Suspended Family']);
        $activeFamily = Family::factory()->create(['name' => 'Active Family']);
        $superAdmin = User::factory()->admin()->superAdmin()->create(['family_id' => $activeFamily->id]);

        $response = $this->actingAs($superAdmin)
            ->get('/platform/families/export');

        $content = $response->streamedContent();
        expect($content)->toContain('Suspended Family')
            ->and($content)->toContain('Active Family');
    });

    it('includes archived status in users export', function () {
        $family = Family::factory()->create();
        $superAdmin = User::factory()->admin()->superAdmin()->create(['family_id' => $family->id]);
        User::factory()->member()->create([
            'family_id' => $family->id,
            'name' => 'Archived User',
            'archived_at' => now(),
        ]);

        $response = $this->actingAs($superAdmin)
            ->get('/platform/users/export');

        $content = $response->streamedContent();
        expect($content)->toContain('Archived');
    });
});
