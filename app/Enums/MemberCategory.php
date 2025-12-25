<?php

declare(strict_types=1);

namespace App\Enums;

enum MemberCategory: string
{
    case Employed = 'employed';
    case Unemployed = 'unemployed';
    case Student = 'student';

    /**
     * Get the monthly contribution amount in kobo.
     * ₦1 = 100 kobo
     */
    public function monthlyAmountInKobo(): int
    {
        return match ($this) {
            self::Employed => 400_000,   // ₦4,000
            self::Unemployed => 200_000, // ₦2,000
            self::Student => 100_000,    // ₦1,000
        };
    }

    /**
     * Get the monthly contribution amount in Naira.
     */
    public function monthlyAmountInNaira(): float
    {
        return $this->monthlyAmountInKobo() / 100;
    }

    /**
     * Get the formatted monthly amount with currency symbol.
     */
    public function formattedAmount(): string
    {
        return '₦'.number_format($this->monthlyAmountInNaira(), 0);
    }

    /**
     * Get the human-readable label for the category.
     */
    public function label(): string
    {
        return match ($this) {
            self::Employed => 'Employed',
            self::Unemployed => 'Unemployed',
            self::Student => 'Student',
        };
    }

    /**
     * Get label with amount for display.
     */
    public function labelWithAmount(): string
    {
        return "{$this->label()} ({$this->formattedAmount()}/month)";
    }
}
