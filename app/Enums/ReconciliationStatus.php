<?php

declare(strict_types=1);

namespace App\Enums;

enum ReconciliationStatus: string
{
    case Unmatched = 'unmatched';
    case Suggested = 'suggested';
    case Matched = 'matched';
    case Ignored = 'ignored';
    case Disputed = 'disputed';

    public function label(): string
    {
        return str($this->value)->headline()->toString();
    }
}
