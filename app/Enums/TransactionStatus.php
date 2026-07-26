<?php

declare(strict_types=1);

namespace App\Enums;

enum TransactionStatus: string
{
    case Initiated = 'initiated';
    case Verified = 'verified';
    case Allocated = 'allocated';
    case Reversed = 'reversed';
    case Pending = 'pending';
    case Success = 'success';
    case Failed = 'failed';
    case Abandoned = 'abandoned';

    public function label(): string
    {
        return match ($this) {
            self::Initiated => 'Initiated',
            self::Verified => 'Verified',
            self::Allocated => 'Allocated',
            self::Reversed => 'Reversed',
            self::Pending => 'Pending',
            self::Success => 'Successful',
            self::Failed => 'Failed',
            self::Abandoned => 'Abandoned',
        };
    }
}
