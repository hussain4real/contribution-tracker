<?php

declare(strict_types=1);

namespace App\Enums;

enum AiActionKind: string
{
    case Payment = 'payment';
    case Expense = 'expense';
    case Adjustment = 'adjustment';
    case Contributions = 'contributions';

    public function destination(): string
    {
        return match ($this) {
            self::Payment => 'payments.create',
            self::Expense => 'expenses.create',
            self::Adjustment => 'fund-adjustments.index',
            self::Contributions => 'contributions.index',
        };
    }
}
