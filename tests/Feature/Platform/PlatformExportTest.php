<?php

declare(strict_types=1);

use App\Models\Family;
use App\Models\User;
use App\Support\PlatformCsvExports;
use Illuminate\Testing\TestResponse;

describe('Platform CSV Exports', function () {
    it('redirects the legacy families export URL to the Filament families page', function () {
        $this->get('/platform/families/export')
            ->assertRedirect('/platform/families');
    });

    it('redirects the legacy users export URL to the Filament users page', function () {
        $this->get('/platform/users/export')
            ->assertRedirect('/platform/users');
    });

    it('exports families as CSV from the shared Filament exporter', function () {
        Family::factory()->create(['name' => 'Test Family']);

        $response = TestResponse::fromBaseResponse(PlatformCsvExports::families());

        $response->assertOk()
            ->assertHeader('content-type', 'text/csv')
            ->assertDownload();

        $content = $response->streamedContent();

        expect($content)->toContain('ID,Name,Slug,Currency')
            ->and($content)->toContain('Test Family');
    });

    it('exports users as CSV from the shared Filament exporter', function () {
        $family = Family::factory()->create();
        User::factory()->admin()->superAdmin()->create([
            'family_id' => $family->id,
            'name' => 'Admin User',
            'email' => 'admin@test.com',
        ]);
        User::factory()->member()->create([
            'family_id' => $family->id,
            'name' => 'Regular Member',
            'email' => 'member@test.com',
        ]);

        $response = TestResponse::fromBaseResponse(PlatformCsvExports::users());

        $response->assertOk()
            ->assertHeader('content-type', 'text/csv')
            ->assertDownload();

        $content = $response->streamedContent();

        expect($content)->toContain('ID,Name,Email,Family')
            ->and($content)->toContain('Admin User')
            ->and($content)->toContain('Regular Member');
    });

    it('includes suspension status in families export', function () {
        Family::factory()->suspended()->create(['name' => 'Suspended Family']);
        Family::factory()->create(['name' => 'Active Family']);

        $content = TestResponse::fromBaseResponse(PlatformCsvExports::families())->streamedContent();

        expect($content)->toContain('Suspended Family')
            ->and($content)->toContain('Suspended')
            ->and($content)->toContain('Active Family')
            ->and($content)->toContain('Active');
    });

    it('includes archived status in users export', function () {
        $family = Family::factory()->create();
        User::factory()->member()->create([
            'family_id' => $family->id,
            'name' => 'Archived User',
            'archived_at' => now(),
        ]);

        $content = TestResponse::fromBaseResponse(PlatformCsvExports::users())->streamedContent();

        expect($content)->toContain('Archived User')
            ->and($content)->toContain('Archived');
    });
});
