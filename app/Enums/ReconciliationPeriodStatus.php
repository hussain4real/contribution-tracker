<?php

declare(strict_types=1);

namespace App\Enums;

enum ReconciliationPeriodStatus: string
{
    case Open = 'open';
    case Closed = 'closed';
    case Reopened = 'reopened';
}
