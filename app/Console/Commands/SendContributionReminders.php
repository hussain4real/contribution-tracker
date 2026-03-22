<?php

namespace App\Console\Commands;

use App\Models\Contribution;
use App\Models\Family;
use App\Notifications\ContributionReminderNotification;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('contributions:remind {--day= : Day of month (25 or 28)}')]
#[Description('Send contribution payment reminders to members with unpaid contributions')]
class SendContributionReminders extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $day = (int) ($this->option('day') ?? now()->day);
        $type = $day >= 28 ? 'follow_up' : 'reminder';

        $year = now()->year;
        $month = now()->month;

        $this->info("Sending {$type} notifications for {$month}/{$year}...");

        $families = Family::query()->has('members')->get();
        $totalSent = 0;

        foreach ($families as $family) {
            $contributions = Contribution::query()
                ->where('family_id', $family->id)
                ->forMonth($year, $month)
                ->incomplete()
                ->with(['user', 'family', 'payments'])
                ->get();

            foreach ($contributions as $contribution) {
                if (! $contribution->user || $contribution->user->isArchived()) {
                    continue;
                }

                $contribution->user->notify(new ContributionReminderNotification($contribution, $type));
                $totalSent++;
            }
        }

        $this->info("Sent {$totalSent} {$type} notifications.");

        return self::SUCCESS;
    }
}
