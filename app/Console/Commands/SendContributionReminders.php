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

#[Signature('contributions:remind {--day= : Day of month (25 or 28)} {--year= : Contribution year} {--month= : Contribution month} {--family= : Specific family ID} {--after= : Contribution IDs above this cursor} {--through= : Last contribution ID in this batch}')]
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

        $year = filter_var($this->option('year') ?? now()->year, FILTER_VALIDATE_INT);
        $month = filter_var($this->option('month') ?? now()->month, FILTER_VALIDATE_INT);
        if ($year === false || $year < 1 || $month === false || $month < 1 || $month > 12) {
            $this->error('The contribution year and month must be valid integers.');

            return self::FAILURE;
        }
        $filters = [];
        foreach (['family', 'after', 'through'] as $option) {
            $value = $this->option($option);
            if ($value !== null) {
                $integer = filter_var($value, FILTER_VALIDATE_INT);
                if ($integer === false || $integer < ($option === 'after' ? 0 : 1)) {
                    $this->error('The family and contribution cursor options must be valid integers.');

                    return self::FAILURE;
                }
                $filters[$option] = $integer;
            }
        }

        $this->info("Sending {$type} notifications for {$month}/{$year}...");

        $totalSent = 0;

        $contributions = Contribution::query();
        if (isset($filters['family'])) {
            $contributions->where('family_id', $filters['family']);
        }
        if (isset($filters['after'])) {
            $contributions->where('id', '>', $filters['after']);
        }
        if (isset($filters['through'])) {
            $contributions->where('id', '<=', $filters['through']);
        }
        $contributions
            ->whereNull($sentAtColumn)
            ->whereHas('family', fn (Builder $query): Builder => $query->has('members'))
            ->whereHas('user.familyMemberships', function ($query): void {
                $query->whereColumn('family_members.family_id', 'contributions.family_id')
                    ->whereNull('family_members.archived_at');
            })
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
