<?php

declare(strict_types=1);

namespace App\Enums;

enum PaymentSource: string
{
    case Manual = 'manual';
    case Paystack = 'paystack';
    case Backfill = 'backfill';
    case Correction = 'correction';

    public function label(): string
    {
        return match ($this) {
            self::Manual => 'Manual',
            self::Paystack => 'Paystack',
            self::Backfill => 'Historical Backfill',
            self::Correction => 'Correction',
        };
    }
}
