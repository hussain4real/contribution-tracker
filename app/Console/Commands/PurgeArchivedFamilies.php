<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\PurgeArchivedFamily;
use App\Models\Family;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('families:purge-archived')]
#[Description('Dispatch idempotent purge jobs for expired archived families not on legal hold')]
class PurgeArchivedFamilies extends Command
{
    public function handle(): int
    {
        $count = 0;
        Family::query()->whereNotNull('archived_at')->whereNull('legal_hold_at')->where('purge_after', '<=', now())
            ->orderBy('id')->chunkById(100, function ($families) use (&$count): void {
                foreach ($families as $family) {
                    PurgeArchivedFamily::dispatch($family->id);
                    $count++;
                }
            });

        $this->info("Dispatched {$count} family purge job(s).");

        return self::SUCCESS;
    }
}
