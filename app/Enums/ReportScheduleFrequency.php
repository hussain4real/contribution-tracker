<?php

declare(strict_types=1);

namespace App\Enums;

use Carbon\CarbonImmutable;

enum ReportScheduleFrequency: string
{
    case Daily = 'daily';
    case Weekly = 'weekly';
    case Monthly = 'monthly';

    public function nextRun(CarbonImmutable $from): CarbonImmutable
    {
        return match ($this) {
            self::Daily => $from->addDay(),
            self::Weekly => $from->addWeek(),
            self::Monthly => $from->addMonthNoOverflow(),
        };
    }
}
