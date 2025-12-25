<?php

declare(strict_types=1);

namespace App\Enums;

enum PaymentStatus: string
{
    case Paid = 'paid';
    case Partial = 'partial';
    case Unpaid = 'unpaid';
    case Overdue = 'overdue';

    /**
     * Get the human-readable label for the status.
     */
    public function label(): string
    {
        return match ($this) {
            self::Paid => 'Paid',
            self::Partial => 'Partial',
            self::Unpaid => 'Unpaid',
            self::Overdue => 'Overdue',
        };
    }

    /**
     * Get the Tailwind color class for the status badge.
     */
    public function color(): string
    {
        return match ($this) {
            self::Paid => 'green',
            self::Partial => 'yellow',
            self::Unpaid => 'gray',
            self::Overdue => 'red',
        };
    }

    /**
     * Get the CSS classes for the status badge.
     */
    public function badgeClasses(): string
    {
        return match ($this) {
            self::Paid => 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200',
            self::Partial => 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200',
            self::Unpaid => 'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-200',
            self::Overdue => 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200',
        };
    }

    /**
     * Check if this status indicates payment is complete.
     */
    public function isComplete(): bool
    {
        return $this === self::Paid;
    }

    /**
     * Check if this status requires attention.
     */
    public function requiresAttention(): bool
    {
        return match ($this) {
            self::Overdue, self::Partial => true,
            self::Paid, self::Unpaid => false,
        };
    }
}
