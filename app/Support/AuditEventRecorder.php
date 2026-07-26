<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\AuditEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class AuditEventRecorder
{
    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     * @param  array<string, mixed>|null  $metadata
     */
    public function record(
        Model $auditable,
        string $action,
        ?int $familyId,
        ?int $actorId = null,
        ?array $before = null,
        ?array $after = null,
        ?array $metadata = null,
    ): AuditEvent {
        $authenticatedUser = auth()->user();

        return AuditEvent::query()->create([
            'family_id' => $familyId,
            'actor_id' => $actorId ?? ($authenticatedUser instanceof User ? $authenticatedUser->id : null),
            'action' => $action,
            'auditable_type' => $auditable->getMorphClass(),
            'auditable_id' => $auditable->getKey(),
            'request_id' => $this->requestId(),
            'before' => $before,
            'after' => $after,
            'metadata' => $metadata,
        ]);
    }

    public function requestId(): string
    {
        if (app()->bound('request-id')) {
            $requestId = app('request-id');

            if (is_string($requestId) && Str::isUuid($requestId)) {
                return $requestId;
            }
        }

        $requestId = (string) Str::uuid();
        app()->instance('request-id', $requestId);

        return $requestId;
    }
}
