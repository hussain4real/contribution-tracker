<?php

declare(strict_types=1);

namespace App\Enums;

enum MemberCategory: string
{
    case Employed = 'employed';
    case Unemployed = 'unemployed';
    case Student = 'student';

    /**
     * Get the monthly contribution amount in Naira.
     */
    public function monthlyAmount(): int
    {
        return match ($this) {
            self::Employed => 4000,   // ₦4,000
            self::Unemployed => 2000, // ₦2,000
            self::Student => 1000,    // ₦1,000
        };
    }

    /**
     * Get the formatted monthly amount with currency symbol.
     */
    public function formattedAmount(): string
    {
        return '₦'.number_format($this->monthlyAmount(), 0);
    }

    /**
     * Get the human-readable label for the category.
     */
    public function label(): string
    {
        return $this->name;
    }

    /**
     * Get label with amount for display.
     */
    public function labelWithAmount(): string
    {
        return "{$this->label()} ({$this->formattedAmount()}/month)";
    }
}
