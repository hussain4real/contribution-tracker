<?php

declare(strict_types=1);

use App\Enums\PaymentRiskAdvisoryBand;
use App\Services\LogisticPaymentRiskScorer;
use App\Services\PaymentRiskArtifactValidator;

/** @return array<string, float> */
function zeroPaymentRiskFeatures(): array
{
    return array_fill_keys(PaymentRiskArtifactValidator::FEATURE_NAMES, 0.0);
}

/**
 * @return array<string, mixed>
 */
function goldenPaymentRiskObject(mixed $value, string $path): array
{
    if (! is_array($value) || array_is_list($value)) {
        throw new RuntimeException("Expected [{$path}] to be a JSON object.");
    }

    $object = [];

    foreach ($value as $key => $item) {
        if (! is_string($key)) {
            throw new RuntimeException("Expected [{$path}] to use string keys.");
        }

        $object[$key] = $item;
    }

    return $object;
}

function goldenPaymentRiskNumber(mixed $value, string $path): float
{
    if (! is_int($value) && ! is_float($value)) {
        throw new RuntimeException("Expected [{$path}] to be numeric.");
    }

    return $value;
}

/** @return non-empty-string */
function goldenPaymentRiskString(mixed $value, string $path): string
{
    if (! is_string($value)) {
        throw new RuntimeException("Expected [{$path}] to be a string.");
    }

    $value = trim($value);

    if ($value === '') {
        throw new RuntimeException("Expected [{$path}] not to be empty.");
    }

    return $value;
}

/**
 * @return array<string, float>
 */
function goldenPaymentRiskFeatureVector(mixed $value): array
{
    $featureVector = [];

    foreach (goldenPaymentRiskObject($value, 'feature_vector') as $feature => $featureValue) {
        $featureVector[$feature] = goldenPaymentRiskNumber($featureValue, "feature_vector.{$feature}");
    }

    return $featureVector;
}

/**
 * @return list<non-empty-string>
 */
function goldenPaymentRiskFactorFeatures(mixed $value): array
{
    if (! is_array($value) || ! array_is_list($value)) {
        throw new RuntimeException('Expected [expected.top_factor_features] to be a JSON list.');
    }

    $features = [];

    foreach ($value as $index => $feature) {
        $features[] = goldenPaymentRiskString($feature, "expected.top_factor_features.{$index}");
    }

    return $features;
}

/**
 * @return array{
 *     linear_score: float,
 *     probability: float,
 *     advisory_band: non-empty-string,
 *     top_factor_features: list<non-empty-string>
 * }
 */
function goldenPaymentRiskExpected(mixed $value): array
{
    $expected = goldenPaymentRiskObject($value, 'expected');

    return [
        'linear_score' => goldenPaymentRiskNumber($expected['linear_score'] ?? null, 'expected.linear_score'),
        'probability' => goldenPaymentRiskNumber($expected['probability'] ?? null, 'expected.probability'),
        'advisory_band' => goldenPaymentRiskString($expected['advisory_band'] ?? null, 'expected.advisory_band'),
        'top_factor_features' => goldenPaymentRiskFactorFeatures($expected['top_factor_features'] ?? null),
    ];
}

/**
 * @return array{
 *     artifact: array<string, mixed>,
 *     feature_vector: array<string, float>,
 *     expected: array{
 *         linear_score: float,
 *         probability: float,
 *         advisory_band: non-empty-string,
 *         top_factor_features: list<non-empty-string>
 *     },
 *     absolute_tolerance: float
 * }
 */
function decodeGoldenPaymentRiskFixture(string $json): array
{
    $fixture = goldenPaymentRiskObject(
        json_decode($json, true, flags: JSON_THROW_ON_ERROR),
        'fixture',
    );

    return [
        'artifact' => goldenPaymentRiskObject($fixture['artifact'] ?? null, 'artifact'),
        'feature_vector' => goldenPaymentRiskFeatureVector($fixture['feature_vector'] ?? null),
        'expected' => goldenPaymentRiskExpected($fixture['expected'] ?? null),
        'absolute_tolerance' => goldenPaymentRiskNumber($fixture['absolute_tolerance'] ?? null, 'absolute_tolerance'),
    ];
}

/**
 * @param  array<string, mixed>  $artifact
 * @return non-empty-string
 */
function goldenPaymentRiskFactorLabel(array $artifact, string $feature): string
{
    $explanation = goldenPaymentRiskObject($artifact['explanation'] ?? null, 'artifact.explanation');
    $factors = $explanation['factors'] ?? null;

    if (! is_array($factors) || ! array_is_list($factors)) {
        throw new RuntimeException('Expected [artifact.explanation.factors] to be a JSON list.');
    }

    foreach ($factors as $index => $value) {
        $factor = goldenPaymentRiskObject($value, "artifact.explanation.factors.{$index}");

        if (($factor['feature'] ?? null) === $feature) {
            return goldenPaymentRiskString($factor['label'] ?? null, "artifact.explanation.factors.{$index}.label");
        }
    }

    throw new RuntimeException("Missing explanation factor for feature [{$feature}].");
}

it('calculates the logistic probability band and deterministic factors', function () {
    $artifact = validPaymentRiskArtifact();
    $artifact = paymentRiskArtifactWith($artifact, 'model.intercept', 0.0);
    $artifact = paymentRiskArtifactWith($artifact, 'model.coefficients', [1.0, -0.5, ...array_fill(0, 12, 0.0)]);
    $artifact = paymentRiskArtifactWith($artifact, 'decision.threshold', 0.7);
    $features = zeroPaymentRiskFeatures();
    $features['expected_amount_minor'] = 1.0;
    $score = (new LogisticPaymentRiskScorer)->score($artifact, $features);

    expect($score['probability'])->toEqualWithDelta(0.7310585786, 1.0E-10)
        ->and($score['advisory_band'])->toBe(PaymentRiskAdvisoryBand::PriorityReview)
        ->and($score['factors'])->toHaveCount(3)
        ->and($score['factors'][0])->toContain('Expected Amount');
});

it('uses a numerically stable sigmoid for extreme logits', function (float $intercept, float $expected) {
    $artifact = validPaymentRiskArtifact();
    $artifact = paymentRiskArtifactWith($artifact, 'model.intercept', $intercept);
    $artifact = paymentRiskArtifactWith($artifact, 'model.coefficients', array_fill(0, 14, 0.0));
    $score = (new LogisticPaymentRiskScorer)->score($artifact, zeroPaymentRiskFeatures());

    expect($score['probability'])->toEqualWithDelta($expected, 1.0E-12);
})->with([
    'large positive logit' => [1000.0, 1.0],
    'large negative logit' => [-1000.0, 0.0],
]);

it('rejects missing reordered or non-finite feature snapshots', function () {
    $artifact = validPaymentRiskArtifact();
    $missing = zeroPaymentRiskFeatures();
    unset($missing['overdue_streak']);
    $nonFinite = zeroPaymentRiskFeatures();
    $nonFinite['expected_amount_minor'] = INF;
    $scorer = new LogisticPaymentRiskScorer;

    expect(fn () => $scorer->score($artifact, $missing))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => $scorer->score($artifact, $nonFinite))
        ->toThrow(InvalidArgumentException::class);
});

it('rejects malformed model vectors fields and explanation data', function (string $path, mixed $value) {
    $artifact = paymentRiskArtifactWith(validPaymentRiskArtifact(), $path, $value);

    expect(fn () => (new LogisticPaymentRiskScorer)->score($artifact, zeroPaymentRiskFeatures()))
        ->toThrow(InvalidArgumentException::class);
})->with([
    'missing explanations' => ['explanation.factors', null],
    'blank explanation label' => ['explanation.factors.0.label', ''],
    'invalid vector length' => ['preprocessing.mean', []],
    'invalid vector value' => ['preprocessing.mean.0', INF],
    'invalid scalar field' => ['model.intercept', []],
]);

it('matches the Python golden scoring fixture', function () {
    $fixtureJson = file_get_contents(dirname(__DIR__, 3).'/ml/payment-risk/tests/fixtures/golden-scoring.json');

    expect($fixtureJson)->toBeString();

    if (! is_string($fixtureJson)) {
        throw new RuntimeException('Expected the Python golden scoring fixture to be readable.');
    }

    $fixture = decodeGoldenPaymentRiskFixture($fixtureJson);
    $artifact = $fixture['artifact'];
    $score = (new LogisticPaymentRiskScorer)->score($artifact, $fixture['feature_vector']);
    $factorLabels = [];

    foreach ($fixture['expected']['top_factor_features'] as $feature) {
        $factorLabels[] = goldenPaymentRiskFactorLabel($artifact, $feature);
    }

    expect($score['probability'])
        ->toEqualWithDelta($fixture['expected']['probability'], $fixture['absolute_tolerance'])
        ->and(log($score['probability'] / (1 - $score['probability'])))
        ->toEqualWithDelta($fixture['expected']['linear_score'], $fixture['absolute_tolerance'])
        ->and($score['advisory_band']->value)->toBe($fixture['expected']['advisory_band']);

    foreach ($factorLabels as $index => $label) {
        expect($score['factors'][$index])->toStartWith($label);
    }
});
