<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Family;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class PurgeArchivedFamily implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $uniqueFor = 3600;

    public function __construct(public readonly int $familyId)
    {
        $this->afterCommit();
    }

    public function uniqueId(): string
    {
        return (string) $this->familyId;
    }

    public function handle(): void
    {
        $family = Family::query()->find($this->familyId);

        if (! $family instanceof Family || ! $family->isArchived() || $family->isOnLegalHold() || $family->purge_after?->isFuture()) {
            return;
        }

        Storage::disk('local')->deleteDirectory("reports/{$family->id}");

        DB::transaction(function () use ($family): void {
            DB::table('payments')->whereIn('contribution_id', DB::table('contributions')->where('family_id', $family->id)->select('id'))->delete();
            DB::table('financial_reversals')->where('family_id', $family->id)->delete();
            DB::table('payment_batches')->where('family_id', $family->id)->delete();
            DB::table('users')->where('family_id', $family->id)->update(['family_id' => null]);
            DB::table('users')->where('current_family_id', $family->id)->update(['current_family_id' => null]);

            Family::withoutEvents(fn (): bool => (bool) $family->delete());
        });
    }
}
