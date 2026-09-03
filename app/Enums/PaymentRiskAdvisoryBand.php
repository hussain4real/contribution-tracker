<?php

declare(strict_types=1);

namespace App\Enums;

enum PaymentRiskAdvisoryBand: string
{
    case RoutineReview = 'routine_review';
    case PriorityReview = 'priority_review';

    public function label(): string
    {
        return match ($this) {
            self::RoutineReview => 'Routine review',
            self::PriorityReview => 'Priority review',
        };
    }
}
