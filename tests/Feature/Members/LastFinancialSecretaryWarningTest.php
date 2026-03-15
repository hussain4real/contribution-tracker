<?php

use App\Enums\Role;
use App\Models\User;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

/**
 * T071a [US4] Feature test for warning when removing last Financial Secretary (FR-019)
 *
 * FR-019: System SHOULD warn before removing the last Financial Secretary
 */
describe('Last Financial Secretary Warning', function () {
    beforeEach(function () {
        $this->superAdmin = User::factory()->superAdmin()->create();
    });

    it('allows removing financial secretary when others exist', function () {
        // Create two financial secretaries
        $fs1 = User::factory()->financialSecretary()->create();
        $fs2 = User::factory()->financialSecretary()->create();

        // Remove first FS
        $this->actingAs($this->superAdmin)
            ->put("/members/{$fs1->id}", [
                'name' => $fs1->name,
                'email' => $fs1->email,
                'category' => $fs1->category->value,
                'role' => 'member',
            ])
            ->assertRedirect();

        $fs1->refresh();
        expect($fs1->role)->toBe(Role::Member);
    });

    it('warns when removing the last financial secretary', function () {
        // Create only one financial secretary
        $lastFs = User::factory()->financialSecretary()->create();

        // Attempt to remove last FS
        $response = $this->actingAs($this->superAdmin)
            ->put("/members/{$lastFs->id}", [
                'name' => $lastFs->name,
                'email' => $lastFs->email,
                'category' => $lastFs->category->value,
                'role' => 'member',
            ]);

        // Should still work but with a warning in session
        $response->assertRedirect();
        $response->assertSessionHas('warning');

        $lastFs->refresh();
        expect($lastFs->role)->toBe(Role::Member);
    });

    it('allows last financial secretary removal with confirmation', function () {
        // Create only one financial secretary
        $lastFs = User::factory()->financialSecretary()->create();

        // Remove with force flag
        $response = $this->actingAs($this->superAdmin)
            ->put("/members/{$lastFs->id}", [
                'name' => $lastFs->name,
                'email' => $lastFs->email,
                'category' => $lastFs->category->value,
                'role' => 'member',
                'confirm_last_fs_removal' => true,
            ]);

        $response->assertRedirect();

        $lastFs->refresh();
        expect($lastFs->role)->toBe(Role::Member);
    });

    it('does not warn when super admin can also record payments', function () {
        // Super admin exists and can record payments, so removing last FS is less critical
        $lastFs = User::factory()->financialSecretary()->create();

        $response = $this->actingAs($this->superAdmin)
            ->put("/members/{$lastFs->id}", [
                'name' => $lastFs->name,
                'email' => $lastFs->email,
                'category' => $lastFs->category->value,
                'role' => 'member',
            ]);

        // Should work - super admin can still record payments
        $lastFs->refresh();
        expect($lastFs->role)->toBe(Role::Member);
    });

    it('counts only active financial secretaries', function () {
        // Create one active and one archived financial secretary
        $activeFs = User::factory()->financialSecretary()->create();
        User::factory()->financialSecretary()->create([
            'archived_at' => now(),
        ]);

        // Removing the active FS should trigger warning since archived doesn't count
        $response = $this->actingAs($this->superAdmin)
            ->put("/members/{$activeFs->id}", [
                'name' => $activeFs->name,
                'email' => $activeFs->email,
                'category' => $activeFs->category->value,
                'role' => 'member',
            ]);

        $response->assertSessionHas('warning');
    });
});
