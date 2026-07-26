<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\ReportScheduleFrequency;
use App\Jobs\GenerateScheduledReport;
use App\Models\ReportSchedule;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('reports:dispatch-scheduled')]
#[Description('Dispatch each due report schedule once for its reporting period')]
class DispatchScheduledReports extends Command
{
    public function handle(): int
    {
        $dispatched = 0;

        ReportSchedule::query()->where('is_active', true)->where('next_run_at', '<=', now())
            ->orderBy('id')->chunkById(100, function ($schedules) use (&$dispatched): void {
                foreach ($schedules as $schedule) {
                    $runAt = CarbonImmutable::instance($schedule->next_run_at)->setTimezone($schedule->timezone);
                    $periodEnd = $runAt->startOfDay()->subDay();
                    $periodStart = match ($schedule->frequency) {
                        ReportScheduleFrequency::Daily => $periodEnd,
                        ReportScheduleFrequency::Weekly => $periodEnd->subDays(6),
                        ReportScheduleFrequency::Monthly => $periodEnd->startOfMonth(),
                    };

                    GenerateScheduledReport::dispatch($schedule->id, $periodStart->toDateString(), $periodEnd->toDateString());
                    $dispatched++;
                }
            });

        $this->info("Dispatched {$dispatched} scheduled report job(s).");

        return self::SUCCESS;
    }
}
