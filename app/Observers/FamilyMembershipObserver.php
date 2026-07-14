<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\FamilyMembership;
use App\Support\AuditEventRecorder;

class FamilyMembershipObserver
{
    /** @var list<string> */
    private const AUDITED_FIELDS = [
        'display_name',
        'role',
        'family_category_id',
        'archived_at',
        'archived_by',
        'archive_reason',
    ];

    public function __construct(private AuditEventRecorder $audit) {}

    public function created(FamilyMembership $membership): void
    {
        $this->audit->record(
            $membership,
            'membership.created',
            $membership->family_id,
            after: $this->snapshot($membership->getAttributes()),
        );
    }

    public function updated(FamilyMembership $membership): void
    {
        $action = match (true) {
            $membership->wasChanged('archived_at') && $membership->archived_at !== null => 'membership.archived',
            $membership->wasChanged('archived_at') => 'membership.restored',
            default => 'membership.updated',
        };

        $this->audit->record(
            $membership,
            $action,
            $membership->family_id,
            before: $this->snapshot($membership->getRawOriginal()),
            after: $this->snapshot($membership->getAttributes()),
        );
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function snapshot(array $attributes): array
    {
        $snapshot = [];

        foreach (self::AUDITED_FIELDS as $field) {
            if (array_key_exists($field, $attributes)) {
                $snapshot[$field] = $attributes[$field];
            }
        }

        return $snapshot;
    }
}
