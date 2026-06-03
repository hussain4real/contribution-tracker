<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Contribution;
use App\Notifications\ContributionReminderNotification;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;

#[Signature('contributions:remind {--day= : Day of month (25 or 28)}')]
#[Description('Send contribution payment reminders to members with unpaid contributions')]
class SendContributionReminders extends Command
{
    private const REMINDER_DELIVERY_LOCK_SECONDS = 30;

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
        $type = $this->reminderTypeForDay($day);
        $sentAtColumn = $this->sentAtColumnFor($type);

        $year = now()->year;
        $month = now()->month;

        $this->info("Sending {$type} notifications for {$month}/{$year}...");

        $totalSent = 0;

        Contribution::query()
            ->whereNull($sentAtColumn)
            ->whereHas('family', fn (Builder $query): Builder => $query->has('members'))
            ->whereHas('user', fn (Builder $query): Builder => $query->whereNull('archived_at'))
            ->forMonth($year, $month)
            ->incomplete()
            ->with(['user', 'family', 'payments'])
            ->chunkById(200, function ($contributions) use (&$totalSent, $sentAtColumn, $type): void {
                foreach ($contributions as $contribution) {
                    $totalSent += (int) $this->sendReminderIfUnsent($contribution, $sentAtColumn, $type);
                }
            });

        $this->info("Sent {$totalSent} {$type} notifications.");

        return self::SUCCESS;
    }

    /**
     * @return 'follow_up'|'reminder'
     */
    private function reminderTypeForDay(int $day): string
    {
        return $day >= 28 ? 'follow_up' : 'reminder';
    }

    /**
     * @param  'follow_up'|'reminder'  $type
     */
    private function sentAtColumnFor(string $type): string
    {
        return $type === 'follow_up' ? 'follow_up_sent_at' : 'reminder_sent_at';
    }

    /**
     * @param  'follow_up'|'reminder'  $type
     */
    private function sendReminderIfUnsent(Contribution $contribution, string $sentAtColumn, string $type): bool
    {
        return (bool) Cache::lock(
            $this->reminderLockKey($contribution, $sentAtColumn),
            self::REMINDER_DELIVERY_LOCK_SECONDS,
        )->get(function () use ($contribution, $sentAtColumn, $type): bool {
            $freshContribution = Contribution::query()
                ->whereKey($contribution->id)
                ->whereNull($sentAtColumn)
                ->with(['user', 'family', 'payments'])
                ->first();

            if (! $freshContribution || ! $freshContribution->user) {
                return false;
            }

            $sentAt = now();

            $freshContribution->user->notify(new ContributionReminderNotification($freshContribution, $type));
            $freshContribution->forceFill([$sentAtColumn => $sentAt])->save();

            return true;
        });
    }

    private function reminderLockKey(Contribution $contribution, string $sentAtColumn): string
    {
        return "contribution-reminder:{$sentAtColumn}:{$contribution->id}";
    }
}
