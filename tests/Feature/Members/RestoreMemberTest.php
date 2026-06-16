<?php

declare(strict_types=1);

use App\Models\Family;
use App\Models\User;

/**
 * T057 [US3] Feature test for restoring archived member
 */
describe('Restore Member', function () {
    beforeEach(function () {
        $this->family = Family::factory()->create();
        $this->admin = User::factory()->admin()->create(['family_id' => $this->family->id]);
        $this->archivedMember = User::factory()->member()->employed()->archived()->create(['family_id' => $this->family->id]);
    });

    it('super admin can restore an archived member', function () {
        expect($this->archivedMember->isArchived())->toBeTrue();

        $this->actingAs($this->admin)
            ->post("/members/{$this->archivedMember->id}/restore")
            ->assertRedirect();

        $this->archivedMember->refresh();
        expect($this->archivedMember->isArchived())->toBeFalse();
        expect($this->archivedMember->archived_at)->toBeNull();
    });

    it('restored member appears in active scope', function () {
        $this->actingAs($this->admin)
            ->post("/members/{$this->archivedMember->id}/restore");

        $this->archivedMember->refresh();

        // Should appear in active query
        $activeMembers = User::active()->where('id', $this->archivedMember->id)->exists();
        expect($activeMembers)->toBeTrue();

        // Should not appear in archived query
        $archivedMembers = User::archived()->where('id', $this->archivedMember->id)->exists();
        expect($archivedMembers)->toBeFalse();
    });

    it('cannot restore non-archived member', function () {
        $activeMember = User::factory()->member()->create(['family_id' => $this->family->id]);

        $this->actingAs($this->admin)
            ->post("/members/{$activeMember->id}/restore")
            ->assertRedirect(); // Should just redirect without error

        $activeMember->refresh();
        expect($activeMember->isArchived())->toBeFalse();
    });

    it('restored member can log in again', function () {
        // First verify archived member cannot access dashboard
        $this->actingAs($this->archivedMember)
            ->get('/dashboard')
            ->assertForbidden();

        // Restore the member
        $this->actingAs($this->admin)
            ->post("/members/{$this->archivedMember->id}/restore");

        $this->archivedMember->refresh();

        // Now they should be able to access dashboard
        $this->actingAs($this->archivedMember)
            ->get('/dashboard')
            ->assertOk();
    });
});
