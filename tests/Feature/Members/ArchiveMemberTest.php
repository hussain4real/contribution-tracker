<?php

declare(strict_types=1);

use App\Models\Contribution;
use App\Models\Family;
use App\Models\FamilyMembership;
use App\Models\User;

/**
 * T056 [US3] Feature test for archiving member (soft delete)
 */
describe('Archive Member', function () {
    beforeEach(function () {
        $this->family = Family::factory()->create();
        $this->admin = User::factory()->admin()->create(['family_id' => $this->family->id]);
        $this->member = User::factory()->member()->employed()->create(['family_id' => $this->family->id]);
    });

    it('super admin can archive a member', function () {
        $this->actingAs($this->admin)
            ->delete("/members/{$this->member->id}")
            ->assertRedirect(route('members.index'));

        $membership = $this->member->membershipForFamilyIncludingArchived($this->family);

        expect($membership)->toBeInstanceOf(FamilyMembership::class)
            ->and($membership?->isArchived())->toBeTrue()
            ->and($membership?->archived_at)->not->toBeNull()
            ->and($this->member->refresh()->archived_at)->toBeNull();
    });

    it('records a supplied family archive reason', function () {
        $this->actingAs($this->admin)
            ->delete("/members/{$this->member->id}", ['reason' => 'Moved to another household'])
            ->assertRedirect(route('members.index'));

        expect($this->member->membershipForFamilyIncludingArchived($this->family)?->archive_reason)
            ->toBe('Moved to another household');
    });

    it('archived member is excluded from active scope', function () {
        $this->actingAs($this->admin)
            ->delete("/members/{$this->member->id}");

        expect($this->member->belongsToFamily($this->family))->toBeFalse()
            ->and($this->member->belongsToFamilyIncludingArchived($this->family))->toBeTrue()
            ->and($this->member->refresh()->isArchived())->toBeFalse();
    });

    it('archived member preserves contribution history', function () {
        // Create some contribution history
        $contribution = Contribution::factory()
            ->forUser($this->member)
            ->currentMonth()
            ->employed()
            ->create();

        $this->actingAs($this->admin)
            ->delete("/members/{$this->member->id}");

        // Contribution should still exist
        $this->assertDatabaseHas('contributions', [
            'id' => $contribution->id,
            'user_id' => $this->member->id,
        ]);
    });

    it('cannot archive super admin', function () {
        $anotherAdmin = User::factory()->admin()->create(['family_id' => $this->family->id]);

        $this->actingAs($this->admin)
            ->delete("/members/{$anotherAdmin->id}")
            ->assertForbidden();

        $anotherAdmin->refresh();
        expect($anotherAdmin->isArchived())->toBeFalse();
    });

    it('cannot archive self', function () {
        $this->actingAs($this->admin)
            ->delete("/members/{$this->admin->id}")
            ->assertForbidden();

        $this->admin->refresh();
        expect($this->admin->isArchived())->toBeFalse();
    });
});
