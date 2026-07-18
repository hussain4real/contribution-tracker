<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\ReportType;
use App\Models\ReportArtifact;
use App\Models\User;

class ReportArtifactPolicy
{
    public function view(User $user, ReportArtifact $artifact): bool
    {
        if ($user->membershipForFamilyId($artifact->family_id) === null) {
            return false;
        }

        if ($user->activeRole()->canGenerateReports()) {
            return true;
        }

        $memberId = $artifact->filters['member_id'] ?? null;

        return in_array($artifact->type, [ReportType::MemberStatement, ReportType::Receipt], true)
            && is_numeric($memberId)
            && intval($memberId) === $user->id;
    }
}
