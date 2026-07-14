<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Family;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ArchiveFamily
{
    public function handle(Family $family, User $actor, string $reason): Family
    {
        if ($family->isArchived()) {
            return $family;
        }

        return DB::transaction(function () use ($family, $actor, $reason): Family {
            $family->forceFill([
                'archived_at' => now(),
                'archived_by' => $actor->id,
                'archive_reason' => $reason,
                'purge_after' => now()->addDays(30),
            ])->save();

            $family->reportSchedules()->update(['is_active' => false]);

            return $family->refresh();
        });
    }
}
