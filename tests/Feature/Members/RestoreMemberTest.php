<?php

declare(strict_types=1);

use App\Models\Family;
use App\Models\FamilyMembership;
use App\Models\User;

/**
 * T057 [US3] Feature test for restoring archived member
 */
describe('Restore Member', function () {
    beforeEach(function () {
        $this->family = Family::factory()->create();
        $this->admin = User::factory()->admin()->create(['family_id' => $this->family->id]);
        $this->archivedMember = User::factory()->member()->employed()->create(['family_id' => $this->family->id]);
        $this->archivedMembership = FamilyMembership::query()
            ->where('family_id', $this->family->id)
            ->where('user_id', $this->archivedMember->id)
            ->firstOrFail();
        $this->archivedMembership->forceFill([
            'archived_at' => now(),
            'archived_by' => $this->admin->id,
            'archive_reason' => 'Testing restore.',
        ])->save();
    });

    it('super admin can restore an archived member', function () {
        expect($this->archivedMembership->isArchived())->toBeTrue();

        $this->actingAs($this->admin)
            ->post("/members/{$this->archivedMember->id}/restore")
            ->assertRedirect();

        expect($this->archivedMember->membershipForFamily($this->family)?->isArchived())->toBeFalse()
            ->and($this->archivedMember->refresh()->archived_at)->toBeNull();
    });

    it('restored member appears in active scope', function () {
        $this->actingAs($this->admin)
            ->post("/members/{$this->archivedMember->id}/restore");

        expect($this->archivedMember->belongsToFamily($this->family))->toBeTrue()
            ->and($this->archivedMember->membershipForFamily($this->family)?->isArchived())->toBeFalse();
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
            ->get(route('dashboard', ['current_family' => $this->family->slug]))
            ->assertForbidden();

        // Restore the member
        $this->actingAs($this->admin)
            ->post("/members/{$this->archivedMember->id}/restore");

        $this->actingAs($this->archivedMember)
            ->get(route('dashboard', ['current_family' => $this->family->slug]))
            ->assertOk();
    });
});
