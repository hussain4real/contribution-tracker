<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Family;
use App\Models\FamilyMembership;
use App\Models\User;

class FamilyFinancialVisibility
{
    public function activeFamily(User $user): ?Family
    {
        $family = $user->currentFamily ?? $user->family;
        if (! $family instanceof Family) {
            return null;
        }

        return Family::query()->whereKey($family->id)->whereNull('archived_at')
            ->whereIn('id', FamilyMembership::query()->active()->where('user_id', $user->id)->select('family_id'))->first();
    }

    public function officer(User $user, Family $family): bool
    {
        $membership = FamilyMembership::query()->active()->where('family_id', $family->id)->where('user_id', $user->id)->first();

        return $membership instanceof FamilyMembership && $membership->role->canViewAllMembers();
    }

    public function totals(User $user, Family $family): bool
    {
        return $this->officer($user, $family) || in_array($family->member_financial_visibility, ['summary', 'transactions'], true);
    }

    public function transactions(User $user, Family $family): bool
    {
        return $this->officer($user, $family) || $family->member_financial_visibility === 'transactions';
    }
}
