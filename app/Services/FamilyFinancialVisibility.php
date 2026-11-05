<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Family;
use App\Models\User;

class FamilyFinancialVisibility
{
    public function totals(User $user, Family $family): bool
    {
        return $user->canViewAllMembers() || in_array($family->member_financial_visibility, ['summary', 'transactions'], true);
    }

    public function transactions(User $user, Family $family): bool
    {
        return $user->canViewAllMembers() || $family->member_financial_visibility === 'transactions';
    }
}
