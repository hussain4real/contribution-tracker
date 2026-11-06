<?php

declare(strict_types=1);

namespace App\Enums;

enum GoalMeasure: string
{
    case NetSavings = 'net_savings';
    case Contributions = 'contributions';

    public function label(): string
    {
        return match ($this) {
            self::NetSavings => 'Net savings',
            self::Contributions => 'Contributions received',
        };
    }
}
