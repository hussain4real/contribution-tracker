<?php

declare(strict_types=1);

namespace App\Enums;

enum TransactionType: string
{
    case Contribution = 'contribution';
    case Subscription = 'subscription';
    case OneTime = 'one_time';
    case Refund = 'refund';

    public function label(): string
    {
        return match ($this) {
            self::Contribution => 'Contribution',
            self::Subscription => 'Subscription',
            self::OneTime => 'One Time',
            self::Refund => 'Refund',
        };
    }
}
