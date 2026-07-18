<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Role;
use App\Models\PaymentBatch;
use App\Models\User;

class PaymentBatchPolicy
{
    public function view(User $user, PaymentBatch $batch): bool
    {
        $membership = $user->membershipForFamilyId($batch->family_id);

        if ($membership === null) {
            return false;
        }

        return $membership->role->canGenerateReports()
            || $batch->membership?->user_id === $user->id;
    }

    public function reverse(User $user, PaymentBatch $batch): bool
    {
        return $user->membershipForFamilyId($batch->family_id)?->role === Role::Admin
            && ! $batch->isReversed();
    }
}
