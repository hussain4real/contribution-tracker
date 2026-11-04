<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Family;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use UnexpectedValueException;

#[Signature('contributions:dispatch-due-reminders')]
#[Description('Dispatch due family contribution reminders in UTC')]
class DispatchDueContributionReminders extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $now = Carbon::now('UTC');
        $month = $now->format('Y-m');
        $failed = false;
        Family::query()->whereNull('archived_at')->chunkById(100, function ($families) use ($now, $month, &$failed): void {
            foreach ($families as $family) {
                try {
                    $settings = $family->reminderSettingsForMonth($month);
                } catch (UnexpectedValueException) {
                    Log::warning('Invalid family contribution reminder settings.', ['family_id' => $family->id, 'month' => $month]);
                    $failed = true;

                    continue;
                }
                foreach ([25 => $settings['reminder_day'], 28 => $settings['follow_up_day']] as $type => $day) {
                    $due = Carbon::parse(sprintf('%s-%02d %s', $month, $day, $settings['time']), 'UTC');
                    if ($due->greaterThan($now)) {
                        continue;
                    }
                    $exit = Artisan::call('contributions:remind', [
                        '--day' => $type, '--family' => $family->id,
                        '--year' => $now->year, '--month' => $now->month, '--no-interaction' => true,
                    ]);
                    $failed = $failed || $exit !== self::SUCCESS;
                }
            }
        });

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
