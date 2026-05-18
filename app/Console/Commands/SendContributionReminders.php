<?php

namespace App\Console\Commands;

use App\Models\Contribution;
use App\Notifications\ContributionReminderNotification;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

#[Signature('contributions:remind {--day= : Day of month (25 or 28)}')]
#[Description('Send contribution payment reminders to members with unpaid contributions')]
class SendContributionReminders extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $dayOption = $this->option('day') ?? now()->day;

        if (! in_array((string) $dayOption, ['25', '28'], true)) {
            $this->error('The --day option must be 25 or 28.');

            return self::FAILURE;
        }

        $day = (int) $dayOption;
        $type = $day >= 28 ? 'follow_up' : 'reminder';
        $sentAtColumn = $this->sentAtColumnFor($type);

        $year = now()->year;
        $month = now()->month;

        $this->info("Sending {$type} notifications for {$month}/{$year}...");

        $totalSent = 0;

        Contribution::query()
            ->whereNull($sentAtColumn)
            ->whereHas('family', fn (Builder $query): Builder => $query->has('members'))
            ->whereHas('user', fn (Builder $query): Builder => $query->active())
            ->forMonth($year, $month)
            ->incomplete()
            ->with(['user', 'family', 'payments'])
            ->chunkById(200, function ($contributions) use (&$totalSent, $sentAtColumn, $type): void {
                foreach ($contributions as $contribution) {
                    if (! $contribution->user) {
                        continue;
                    }

                    $contribution->user->notify(new ContributionReminderNotification($contribution, $type));
                    $contribution->forceFill([$sentAtColumn => now()])->save();
                    $totalSent++;
                }
            });

        $this->info("Sent {$totalSent} {$type} notifications.");

        return self::SUCCESS;
    }

    private function sentAtColumnFor(string $type): string
    {
        return $type === 'follow_up' ? 'follow_up_sent_at' : 'reminder_sent_at';
    }
}
