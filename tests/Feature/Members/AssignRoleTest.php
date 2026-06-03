<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Models\User;

/**
 * @return array{name: string, email: string, category: string, role: string}
 */
function assignRolePayload(User $member, string $role): array
{
    $category = $member->category;

    if ($category === null) {
        throw new RuntimeException('Expected member to have a category.');
    }

    return [
        'name' => $member->name,
        'email' => $member->email,
        'category' => $category->value,
        'role' => $role,
    ];
}

/**
 * T069 [US4] Feature test for assigning Financial Secretary role
 */
describe('Assign Role', function () {
    beforeEach(function () {
        $this->admin = User::factory()->admin()->create();
    });

    it('super admin can assign financial secretary role to a member', function () {
        $member = User::factory()->member()->create();

        expect($member->role)->toBe(Role::Member);

        $this->actingAs($this->admin)
            ->put("/members/{$member->id}", assignRolePayload($member, 'financial_secretary'))
            ->assertRedirect();

        $member->refresh();
        expect($member->role)->toBe(Role::FinancialSecretary);
    });

    it('super admin can assign super admin role to a member', function () {
        $member = User::factory()->member()->create();

        $this->actingAs($this->admin)
            ->put("/members/{$member->id}", assignRolePayload($member, 'admin'))
            ->assertRedirect();

        $member->refresh();
        expect($member->role)->toBe(Role::Admin);
    });

    it('financial secretary cannot assign roles', function () {
        $financialSecretary = User::factory()->financialSecretary()->create();
        $member = User::factory()->member()->create();

        $this->actingAs($financialSecretary)
            ->put("/members/{$member->id}", assignRolePayload($member, 'financial_secretary'))
            ->assertForbidden();

        $member->refresh();
        expect($member->role)->toBe(Role::Member);
    });

    it('member cannot assign roles', function () {
        $regularMember = User::factory()->member()->create();
        $targetMember = User::factory()->member()->create();

        $this->actingAs($regularMember)
            ->put("/members/{$targetMember->id}", assignRolePayload($targetMember, 'financial_secretary'))
            ->assertForbidden();
    });

    it('assigned financial secretary can record payments', function () {
        $member = User::factory()->member()->create();

        // Assign FS role
        $this->actingAs($this->admin)
            ->put("/members/{$member->id}", assignRolePayload($member, 'financial_secretary'));

        $member->refresh();

        // Verify can record payments
        expect($member->canRecordPayments())->toBeTrue();
    });

    it('returns success flash message after role assignment', function () {
        $member = User::factory()->member()->create();

        $response = $this->actingAs($this->admin)
            ->put("/members/{$member->id}", assignRolePayload($member, 'financial_secretary'));

        $response->assertSessionHas('success');
    });
});
