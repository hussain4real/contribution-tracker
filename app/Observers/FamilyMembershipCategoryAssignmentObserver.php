<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\FamilyMembershipCategoryAssignment;
use App\Support\AuditEventRecorder;

class FamilyMembershipCategoryAssignmentObserver
{
    public function __construct(private AuditEventRecorder $audit) {}

    public function created(FamilyMembershipCategoryAssignment $assignment): void
    {
        $assignment->loadMissing('membership');

        $this->audit->record(
            $assignment,
            'membership.category_assigned',
            $assignment->membership->family_id,
            $assignment->assigned_by,
            after: [
                'family_membership_id' => $assignment->family_membership_id,
                'family_category_id' => $assignment->family_category_id,
                'effective_from' => $assignment->effective_from->toDateString(),
                'effective_until' => $assignment->effective_until?->toDateString(),
            ],
        );
    }
}
