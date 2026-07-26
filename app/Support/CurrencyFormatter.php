<?php

declare(strict_types=1);

namespace App\Support;

final class CurrencyFormatter
{
    public static function format(int|float $amount, ?string $currency, int $fractionDigits = 2): string
    {
        $normalizedCurrency = trim((string) $currency);
        $normalizedCurrency = $normalizedCurrency !== '' ? $normalizedCurrency : '₦';
        $formattedAmount = number_format((float) $amount, $fractionDigits);

        if (preg_match('/^[A-Z]{3}$/', $normalizedCurrency) === 1) {
            return "{$normalizedCurrency} {$formattedAmount}";
        }

        return "{$normalizedCurrency}{$formattedAmount}";
    }
}
