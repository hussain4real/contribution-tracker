<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Family;
use Illuminate\Validation\ValidationException;

class RestoreFamily
{
    public function handle(Family $family): Family
    {
        if (! $family->isArchived()) {
            return $family;
        }

        if ($family->purge_after?->isPast()) {
            throw ValidationException::withMessages([
                'family' => 'The 30-day restoration window has expired.',
            ]);
        }

        $family->forceFill([
            'archived_at' => null,
            'archived_by' => null,
            'archive_reason' => null,
            'purge_after' => null,
        ])->save();

        return $family->refresh();
    }
}
