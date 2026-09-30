<?php

declare(strict_types=1);

namespace App\Enums;

enum PaymentRiskHistoryTier: string
{
    case Unavailable = 'unavailable';
    case Pooled = 'pooled';
    case Experimental = 'experimental';
    case Standard = 'standard';

    public static function forHistoryCount(int $periods): self
    {
        return match (true) {
            $periods < 3 => self::Unavailable,
            $periods < 6 => self::Pooled,
            $periods < 12 => self::Experimental,
            default => self::Standard,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Unavailable => 'Insufficient history',
            self::Pooled => 'Pooled baseline',
            self::Experimental => 'Experimental',
            self::Standard => 'Standard advisory',
        };
    }

    public function warning(): ?string
    {
        return match ($this) {
            self::Unavailable => 'No score is available because fewer than three mature periods were recorded before the cutoff.',
            self::Pooled => 'Only the portfolio baseline is shown; this is not an individualized probability.',
            self::Experimental => 'This score is experimental because the member has fewer than twelve mature periods.',
            self::Standard => null,
        };
    }
}
