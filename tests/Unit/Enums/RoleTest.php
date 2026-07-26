<?php

declare(strict_types=1);

use App\Enums\Role;

it('exposes role labels and permissions', function (
    Role $role,
    string $label,
    bool $canRecordPayments,
    bool $canAddMembers,
    bool $canManageMembers,
    bool $canManageRoles,
    bool $canViewAllMembers,
    bool $canGenerateReports,
) {
    expect($role->label())->toBe($label)
        ->and($role->canRecordPayments())->toBe($canRecordPayments)
        ->and($role->canAddMembers())->toBe($canAddMembers)
        ->and($role->canManageMembers())->toBe($canManageMembers)
        ->and($role->canManageRoles())->toBe($canManageRoles)
        ->and($role->canViewAllMembers())->toBe($canViewAllMembers)
        ->and($role->canGenerateReports())->toBe($canGenerateReports);
})->with([
    'admin' => [Role::Admin, 'Admin', true, true, true, true, true, true],
    'financial secretary' => [Role::FinancialSecretary, 'Financial Secretary', true, true, false, false, true, true],
    'member' => [Role::Member, 'Member', false, false, false, false, false, false],
]);
