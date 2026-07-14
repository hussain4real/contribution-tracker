<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\PaymentBatch;
use App\Models\User;

class PaymentBatchPolicy
{
    public function view(User $user, PaymentBatch $batch): bool
    {
        if ($user->membershipForFamilyId($batch->family_id) === null) {
            return false;
        }

        return $user->activeRole()->canGenerateReports()
            || $batch->membership?->user_id === $user->id;
    }

    public function reverse(User $user, PaymentBatch $batch): bool
    {
        return $user->isAdmin()
            && $user->membershipForFamilyId($batch->family_id) !== null
            && ! $batch->isReversed();
    }
}
