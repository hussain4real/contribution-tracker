<?php

declare(strict_types=1);

namespace App\Enums;

enum BankTransactionDirection: string
{
    case Credit = 'credit';
    case Debit = 'debit';

    public function label(): string
    {
        return match ($this) {
            self::Credit => 'Money in',
            self::Debit => 'Money out',
        };
    }
}
