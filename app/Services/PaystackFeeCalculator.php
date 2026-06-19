<?php

declare(strict_types=1);

namespace App\Services;

final class PaystackFeeCalculator
{
    /**
     * @return array{
     *     contribution_amount_kobo: int,
     *     gross_amount_kobo: int,
     *     estimated_fee_kobo: int,
     *     settled_amount_kobo: int,
     *     fee_policy: string
     * }
     */
    public function estimateForContributionAmount(int $amount): array
    {
        $targetAmountKobo = max(0, $amount * 100);

        if ($targetAmountKobo === 0) {
            return [
                'contribution_amount_kobo' => 0,
                'gross_amount_kobo' => 0,
                'estimated_fee_kobo' => 0,
                'settled_amount_kobo' => 0,
                'fee_policy' => $this->feePolicy(),
            ];
        }

        $grossAmountKobo = $this->grossAmountForSettlement($targetAmountKobo);
        $estimatedFeeKobo = $this->feeForGrossAmountKobo($grossAmountKobo);

        return [
            'contribution_amount_kobo' => $targetAmountKobo,
            'gross_amount_kobo' => $grossAmountKobo,
            'estimated_fee_kobo' => $estimatedFeeKobo,
            'settled_amount_kobo' => $grossAmountKobo - $estimatedFeeKobo,
            'fee_policy' => $this->feePolicy(),
        ];
    }

    public function feeForGrossAmountKobo(int $grossAmountKobo): int
    {
        $grossAmountKobo = max(0, $grossAmountKobo);

        if ($grossAmountKobo === 0) {
            return 0;
        }

        $percentageFeeKobo = $this->ceilDiv($grossAmountKobo * $this->basisPoints(), 10_000);
        $fixedFeeKobo = $grossAmountKobo < $this->fixedFeeWaiverThresholdKobo()
            ? 0
            : $this->fixedFeeKobo();

        return min($percentageFeeKobo + $fixedFeeKobo, $this->feeCapKobo());
    }

    /**
     * @return array{
     *     policy: string,
     *     basis_points: int,
     *     fixed_kobo: int,
     *     waiver_threshold_kobo: int,
     *     cap_kobo: int
     * }
     */
    public function publicConfig(): array
    {
        return [
            'policy' => $this->feePolicy(),
            'basis_points' => $this->basisPoints(),
            'fixed_kobo' => $this->fixedFeeKobo(),
            'waiver_threshold_kobo' => $this->fixedFeeWaiverThresholdKobo(),
            'cap_kobo' => $this->feeCapKobo(),
        ];
    }

    public function feePolicy(): string
    {
        $policy = config('services.paystack.fee_policy', 'payer_pays');

        return is_string($policy) && $policy !== '' ? $policy : 'payer_pays';
    }

    private function grossAmountForSettlement(int $targetAmountKobo): int
    {
        $low = $targetAmountKobo;
        $high = $targetAmountKobo + max($this->feeForGrossAmountKobo($targetAmountKobo), $this->fixedFeeKobo(), $this->feeCapKobo());

        while ($low < $high) {
            $mid = intdiv($low + $high, 2);

            if (($mid - $this->feeForGrossAmountKobo($mid)) >= $targetAmountKobo) {
                $high = $mid;
            } else {
                $low = $mid + 1;
            }
        }

        return $low;
    }

    private function basisPoints(): int
    {
        return $this->integerConfig('local_fee_basis_points', 150);
    }

    private function fixedFeeKobo(): int
    {
        return $this->integerConfig('local_fee_fixed_kobo', 10_000);
    }

    private function fixedFeeWaiverThresholdKobo(): int
    {
        return $this->integerConfig('local_fee_fixed_waiver_threshold_kobo', 250_000);
    }

    private function feeCapKobo(): int
    {
        return $this->integerConfig('local_fee_cap_kobo', 200_000);
    }

    private function integerConfig(string $key, int $default): int
    {
        $value = config("services.paystack.{$key}", $default);

        return is_numeric($value) ? max(0, (int) $value) : $default;
    }

    private function ceilDiv(int $numerator, int $denominator): int
    {
        return intdiv($numerator + $denominator - 1, $denominator);
    }
}
