<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;

class ReconciliationPolicy
{
    public function manage(User $user): bool
    {
        return $user->activeRole()->canGenerateReports();
    }

    public function reopen(User $user): bool
    {
        return $user->isAdmin();
    }
}
