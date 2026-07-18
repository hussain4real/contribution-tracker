<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ReportSchedule;
use App\Models\User;

class ReportSchedulePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->activeRole()->canGenerateReports();
    }

    public function create(User $user): bool
    {
        return $user->activeRole()->canGenerateReports();
    }

    public function delete(User $user, ReportSchedule $schedule): bool
    {
        return ($user->current_family_id ?? $user->family_id) === $schedule->family_id
            && $user->membershipForFamilyId($schedule->family_id)?->role->canGenerateReports() === true;
    }
}
