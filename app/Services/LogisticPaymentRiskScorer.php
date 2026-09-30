<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\PaymentRiskAdvisoryBand;
use InvalidArgumentException;

final class LogisticPaymentRiskScorer
{
    /**
     * @param  array<string, mixed>  $artifact
     * @param  array<string, float>  $features
     * @return array{probability: float, advisory_band: PaymentRiskAdvisoryBand, factors: list<string>}
     */
    public function score(array $artifact, array $features): array
    {
        if (array_keys($features) !== PaymentRiskArtifactValidator::FEATURE_NAMES) {
            throw new InvalidArgumentException('The payment-risk feature snapshot is incompatible.');
        }

        $means = $this->vector($artifact, 'preprocessing.mean');
        $scales = $this->vector($artifact, 'preprocessing.scale');
        $coefficients = $this->vector($artifact, 'model.coefficients');
        $intercept = $this->number($artifact, 'model.intercept');
        $threshold = $this->number($artifact, 'decision.threshold');
        $explanations = data_get($artifact, 'explanation.factors');

        if (! is_array($explanations)) {
            throw new InvalidArgumentException('The payment-risk explanation schema is missing.');
        }

        $logit = $intercept;
        $factorContributions = [];

        foreach (PaymentRiskArtifactValidator::FEATURE_NAMES as $index => $featureName) {
            $value = $features[$featureName];

            if (! is_finite($value)) {
                throw new InvalidArgumentException("The payment-risk feature [{$featureName}] is not finite.");
            }

            $standardizedValue = ($value - $means[$index]) / $scales[$index];
            $contribution = $coefficients[$index] * $standardizedValue;
            $logit += $contribution;
            $label = data_get($explanations, "{$index}.label");

            if (! is_string($label) || trim($label) === '') {
                throw new InvalidArgumentException('The payment-risk explanation label is invalid.');
            }

            $factorContributions[] = [
                'index' => $index,
                'magnitude' => abs($contribution),
                'contribution' => $contribution,
                'label' => trim($label),
            ];
        }

        $probability = $this->sigmoid($logit);
        $band = $probability >= $threshold
            ? PaymentRiskAdvisoryBand::PriorityReview
            : PaymentRiskAdvisoryBand::RoutineReview;

        usort($factorContributions, function (array $left, array $right): int {
            $byMagnitude = $right['magnitude'] <=> $left['magnitude'];

            return $byMagnitude !== 0 ? $byMagnitude : $left['index'] <=> $right['index'];
        });

        $factors = array_map(
            fn (array $factor): string => sprintf(
                '%s was associated with a %s advisory score.',
                $factor['label'],
                $factor['contribution'] >= 0 ? 'higher' : 'lower',
            ),
            array_slice($factorContributions, 0, 3),
        );

        return [
            'probability' => $probability,
            'advisory_band' => $band,
            'factors' => $factors,
        ];
    }

    private function sigmoid(float $value): float
    {
        if ($value >= 0) {
            return 1 / (1 + exp(-$value));
        }

        $exponential = exp($value);

        return $exponential / (1 + $exponential);
    }

    /**
     * @param  array<string, mixed>  $artifact
     * @return list<float>
     */
    private function vector(array $artifact, string $path): array
    {
        $values = data_get($artifact, $path);

        if (! is_array($values) || count($values) !== count(PaymentRiskArtifactValidator::FEATURE_NAMES)) {
            throw new InvalidArgumentException("The payment-risk vector [{$path}] is invalid.");
        }

        return array_map(function (mixed $value) use ($path): float {
            if (! is_numeric($value) || ! is_finite((float) $value)) {
                throw new InvalidArgumentException("The payment-risk vector [{$path}] is invalid.");
            }

            return (float) $value;
        }, array_values($values));
    }

    /** @param array<string, mixed> $artifact */
    private function number(array $artifact, string $path): float
    {
        $value = data_get($artifact, $path);

        if (! is_numeric($value) || ! is_finite((float) $value)) {
            throw new InvalidArgumentException("The payment-risk field [{$path}] is invalid.");
        }

        return (float) $value;
    }
}
