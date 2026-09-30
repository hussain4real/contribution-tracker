<?php

declare(strict_types=1);

namespace App\Services;

use Carbon\CarbonImmutable;
use InvalidArgumentException;
use JsonException;

final class PaymentRiskArtifactValidator
{
    public const SCHEMA_VERSION = 'payment-risk-logistic-v1';

    public const FEATURE_CONTRACT_VERSION = 'payment-risk-features-v1';

    public const ACTIVATION_POLICY_VERSION = 'payment-risk-activation-v1';

    public const SPLIT_POLICY = 'chronological_complete_period_60_20_20';

    public const RANDOM_SEED = 41729;

    public const MINIMUM_VALIDATION_RECALL = 0.70;

    private const SELECTION_OBJECTIVE = 'max_overdue_f1_subject_to_recall_at_least_0_70_tie_precision';

    /** @var list<string> */
    private const ACTIVATION_CHECKS = [
        'consented_anonymized_provenance',
        'data_quality_gate_passed',
        'validation_recall_at_least_0_70',
        'held_out_f1_beats_both_baselines',
        'held_out_balanced_accuracy_above_0_5',
        'held_out_brier_beats_training_prevalence',
    ];

    /** @var list<string> */
    public const FEATURE_NAMES = [
        'expected_amount_minor',
        'days_until_due',
        'calendar_month',
        'calendar_quarter',
        'previous_mature_period_count',
        'prior_3_on_time_rate',
        'prior_6_on_time_rate',
        'prior_lifetime_on_time_rate',
        'prior_partial_payment_rate',
        'mean_recorded_settlement_delay_days',
        'median_recorded_settlement_delay_days',
        'prior_outstanding_count',
        'prior_outstanding_amount_minor',
        'overdue_streak',
    ];

    /** @var list<string> */
    private const TOP_LEVEL_KEYS = [
        'artifact_schema_version',
        'model_version',
        'generated_at',
        'training',
        'data_quality',
        'preprocessing',
        'model',
        'decision',
        'evaluation',
        'explanation',
    ];

    /**
     * @return array<string, mixed>
     */
    public function validateFile(string $path): array
    {
        $resolvedPath = realpath($path);

        if ($resolvedPath === false || ! is_file($resolvedPath) || ! is_readable($resolvedPath)) {
            throw new InvalidArgumentException('The payment-risk artifact does not exist or is not readable.');
        }

        $contents = file_get_contents($resolvedPath) ?: throw new InvalidArgumentException('The payment-risk artifact could not be read.');

        return $this->validateJson($contents);
    }

    /**
     * @return array<string, mixed>
     */
    public function validateJson(string $json): array
    {
        $maxBytes = $this->configuredInteger('payment-risk.max_artifact_bytes', 2 * 1024 * 1024);

        if ($json === '' || strlen($json) > $maxBytes) {
            throw new InvalidArgumentException('The payment-risk artifact is empty or exceeds the size limit.');
        }

        try {
            $artifact = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('The payment-risk artifact is not valid JSON.', previous: $exception);
        }

        $artifact = $this->associativeArray($artifact, 'root');

        $this->validateTopLevel($artifact);
        $this->validateTraining($artifact);
        $this->validateModel($artifact);
        $this->validateDataQuality($artifact);
        $this->validateEvaluation($artifact);
        $this->validateExplanations($artifact);

        return $artifact;
    }

    /** @param array<string, mixed> $artifact */
    public function isActivationEligible(array $artifact): bool
    {
        if (! $this->hasActivationSemantics($artifact)) {
            return false;
        }

        $validRows = $this->integerAt($artifact, 'data_quality.valid_row_count');
        $members = $this->integerAt($artifact, 'data_quality.distinct_member_count');
        $periods = $this->integerAt($artifact, 'data_quality.distinct_period_count');
        $onTime = $this->integerAt($artifact, 'data_quality.on_time_count');
        $overdue = $this->integerAt($artifact, 'data_quality.overdue_count');

        if (
            $validRows < $this->configuredInteger('payment-risk.minimum_valid_rows', 500)
            || $members < $this->configuredInteger('payment-risk.minimum_member_histories', 50)
            || $periods < $this->configuredInteger('payment-risk.minimum_periods', 12)
            || $onTime < $this->configuredInteger('payment-risk.minimum_class_count', 100)
            || $overdue < $this->configuredInteger('payment-risk.minimum_class_count', 100)
        ) {
            return false;
        }

        $validationRecall = $this->numberAt($artifact, 'evaluation.validation.recall_overdue');
        $heldOutF1 = $this->numberAt($artifact, 'evaluation.held_out.overdue_f1');
        $balancedAccuracy = $this->numberAt($artifact, 'evaluation.held_out.balanced_accuracy');
        $heldOutBrier = $this->numberAt($artifact, 'evaluation.held_out.brier_score');
        $prevalenceF1 = $this->numberAt($artifact, 'evaluation.baselines.training_prevalence.overdue_f1');
        $prevalenceBrier = $this->numberAt($artifact, 'evaluation.baselines.training_prevalence.brier_score');
        $previousPeriodF1 = $this->numberAt($artifact, 'evaluation.baselines.previous_period_late.overdue_f1');

        $expectedChecks = [
            'consented_anonymized_provenance' => true,
            'data_quality_gate_passed' => true,
            'validation_recall_at_least_0_70' => $validationRecall >= self::MINIMUM_VALIDATION_RECALL,
            'held_out_f1_beats_both_baselines' => $heldOutF1 > max($prevalenceF1, $previousPeriodF1),
            'held_out_balanced_accuracy_above_0_5' => $balancedAccuracy > 0.5,
            'held_out_brier_beats_training_prevalence' => $heldOutBrier < $prevalenceBrier,
        ];

        return $this->matchesBooleanMap(data_get($artifact, 'evaluation.activation_checks'), $expectedChecks)
            && data_get($artifact, 'evaluation.activation_eligible') === true
            && $validationRecall >= self::MINIMUM_VALIDATION_RECALL
            && $heldOutF1 > max($prevalenceF1, $previousPeriodF1)
            && $balancedAccuracy > 0.5
            && $heldOutBrier < $prevalenceBrier;
    }

    /** @param array<string, mixed> $artifact */
    private function validateTopLevel(array $artifact): void
    {
        $missing = array_diff(self::TOP_LEVEL_KEYS, array_keys($artifact));
        $unexpected = array_diff(array_keys($artifact), self::TOP_LEVEL_KEYS);

        if ($missing !== [] || $unexpected !== []) {
            throw new InvalidArgumentException('The payment-risk artifact has missing or unsupported top-level fields.');
        }

        if ($artifact['artifact_schema_version'] !== self::SCHEMA_VERSION) {
            throw new InvalidArgumentException('The payment-risk artifact schema is incompatible.');
        }

        $version = $this->stringAt($artifact, 'model_version');

        if (preg_match('/^[A-Za-z0-9._-]{1,100}$/', $version) !== 1) {
            throw new InvalidArgumentException('The payment-risk model version is invalid.');
        }

        $generatedAt = $this->parseDateTime($artifact['generated_at'], 'generated_at');

        if ($generatedAt->greaterThan(now()->addMinutes(5))) {
            throw new InvalidArgumentException('The payment-risk artifact generation timestamp is in the future.');
        }
    }

    /** @param array<string, mixed> $artifact */
    private function validateTraining(array $artifact): void
    {
        $sourceType = $this->stringAt($artifact, 'training.source_type');
        $datasetId = $this->stringAt($artifact, 'training.dataset_id');

        if (! in_array($sourceType, ['consented_anonymized', 'synthetic'], true)) {
            throw new InvalidArgumentException('The payment-risk training source type is invalid.');
        }

        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{2,63}$/', $datasetId) !== 1) {
            throw new InvalidArgumentException('The payment-risk training dataset identifier is invalid.');
        }

        if (data_get($artifact, 'training.feature_contract_version') !== self::FEATURE_CONTRACT_VERSION) {
            throw new InvalidArgumentException('The payment-risk feature-contract version is incompatible.');
        }

        if (data_get($artifact, 'training.feature_names') !== self::FEATURE_NAMES) {
            throw new InvalidArgumentException('The payment-risk feature schema is incompatible.');
        }

        $windowStart = $this->parseDate(data_get($artifact, 'training.window.start'), 'training.window.start');
        $windowEnd = $this->parseDate(data_get($artifact, 'training.window.end'), 'training.window.end');

        if ($windowEnd->lessThan($windowStart)) {
            throw new InvalidArgumentException('The payment-risk training window is invalid.');
        }

        $seed = data_get($artifact, 'training.seed');

        if ($seed !== self::RANDOM_SEED) {
            throw new InvalidArgumentException('The payment-risk training seed is invalid.');
        }

        $prevalence = $this->numberAt($artifact, 'training.overdue_prevalence');

        if ($prevalence < 0 || $prevalence > 1) {
            throw new InvalidArgumentException('The payment-risk training prevalence is invalid.');
        }

        $split = $this->associativeArrayAt($artifact, 'training.split');
        $splitPolicy = data_get($split, 'policy');

        if ($sourceType === 'consented_anonymized' && $splitPolicy !== self::SPLIT_POLICY) {
            throw new InvalidArgumentException('The payment-risk chronological split policy is incompatible.');
        }

        if ($sourceType === 'synthetic' && ! in_array($splitPolicy, [self::SPLIT_POLICY, 'golden_fixture'], true)) {
            throw new InvalidArgumentException('The payment-risk synthetic split policy is invalid.');
        }

        if ($splitPolicy === self::SPLIT_POLICY) {
            $this->validateChronologicalSplit(
                $artifact,
                $windowStart,
                $windowEnd,
                $this->parseDateTime(data_get($artifact, 'generated_at'), 'generated_at'),
            );
        }
    }

    /** @param array<string, mixed> $artifact */
    private function validateModel(array $artifact): void
    {
        if (
            data_get($artifact, 'preprocessing.type') !== 'z_score_standardization'
            || data_get($artifact, 'preprocessing.formula') !== 'standardized=(value-mean)/scale'
        ) {
            throw new InvalidArgumentException('The payment-risk preprocessing contract is incompatible.');
        }

        if (data_get($artifact, 'model.type') !== 'l2_logistic_regression') {
            throw new InvalidArgumentException('Only L2 logistic-regression artifacts are supported.');
        }

        if (
            data_get($artifact, 'model.penalty') !== 'l2'
            || data_get($artifact, 'model.class_weight') !== 'balanced'
            || data_get($artifact, 'model.solver') !== 'liblinear'
            || data_get($artifact, 'model.positive_class') !== 'overdue'
        ) {
            throw new InvalidArgumentException('The payment-risk logistic-model contract is incompatible.');
        }

        if (
            data_get($artifact, 'decision.positive_class') !== 'overdue'
            || data_get($artifact, 'decision.negative_class') !== 'on_time'
        ) {
            throw new InvalidArgumentException('The payment-risk decision classes are incompatible.');
        }

        $this->numberAt($artifact, 'model.intercept');
        $threshold = $this->numberAt($artifact, 'decision.threshold');

        if ($threshold <= 0 || $threshold >= 1) {
            throw new InvalidArgumentException('The payment-risk decision threshold must be between zero and one.');
        }

        $riskBands = data_get($artifact, 'decision.risk_bands');

        if (! is_array($riskBands) || ! array_is_list($riskBands) || count($riskBands) !== 2) {
            throw new InvalidArgumentException('The payment-risk decision bands are invalid.');
        }

        if (
            data_get($riskBands, '0.label') !== 'routine_review'
            || data_get($riskBands, '1.label') !== 'priority_review'
            || $this->numberAt($artifact, 'decision.risk_bands.0.minimum_inclusive') !== 0.0
            || abs($this->numberAt($artifact, 'decision.risk_bands.0.maximum_exclusive') - $threshold) > 1.0E-12
            || abs($this->numberAt($artifact, 'decision.risk_bands.1.minimum_inclusive') - $threshold) > 1.0E-12
            || $this->numberAt($artifact, 'decision.risk_bands.1.maximum_inclusive') !== 1.0
        ) {
            throw new InvalidArgumentException('The payment-risk decision bands are invalid.');
        }

        $this->numericVector($artifact, 'model.coefficients', allowZero: true);
        $this->numericVector($artifact, 'preprocessing.mean', allowZero: true);
        $this->numericVector($artifact, 'preprocessing.scale', allowZero: false);
    }

    /** @param array<string, mixed> $artifact */
    private function validateDataQuality(array $artifact): void
    {
        foreach ([
            'valid_row_count',
            'distinct_member_count',
            'distinct_period_count',
            'on_time_count',
            'overdue_count',
        ] as $field) {
            if ($this->integerAt($artifact, "data_quality.{$field}") < 0) {
                throw new InvalidArgumentException("The payment-risk data-quality field [{$field}] is invalid.");
            }
        }

        if (
            $this->integerAt($artifact, 'data_quality.on_time_count')
            + $this->integerAt($artifact, 'data_quality.overdue_count')
            !== $this->integerAt($artifact, 'data_quality.valid_row_count')
        ) {
            throw new InvalidArgumentException('The payment-risk data-quality class counts are inconsistent.');
        }

        if (! is_bool(data_get($artifact, 'data_quality.gate_passed'))) {
            throw new InvalidArgumentException('The payment-risk data-quality gate is invalid.');
        }

        if (
            data_get($artifact, 'data_quality.gates') !== null
            || data_get($artifact, 'training.source_type') === 'consented_anonymized'
        ) {
            $gates = $this->associativeArrayAt($artifact, 'data_quality.gates');
            $gateKeys = array_keys($this->expectedDataQualityGates($artifact));

            if (array_diff($gateKeys, array_keys($gates)) !== [] || array_diff(array_keys($gates), $gateKeys) !== []) {
                throw new InvalidArgumentException('The payment-risk data-quality gates are incomplete.');
            }

            foreach ($gates as $gate) {
                if (! is_bool($gate)) {
                    throw new InvalidArgumentException('The payment-risk data-quality gates must be boolean.');
                }
            }
        }

        $sourceType = data_get($artifact, 'data_quality.source_type');

        if ($sourceType !== null && $sourceType !== data_get($artifact, 'training.source_type')) {
            throw new InvalidArgumentException('The payment-risk data-quality provenance is inconsistent.');
        }

        $datasetId = data_get($artifact, 'data_quality.dataset_id');

        if ($datasetId !== null && $datasetId !== data_get($artifact, 'training.dataset_id')) {
            throw new InvalidArgumentException('The payment-risk data-quality dataset is inconsistent.');
        }
    }

    /** @param array<string, mixed> $artifact */
    private function validateEvaluation(array $artifact): void
    {
        $activationEligible = data_get($artifact, 'evaluation.activation_eligible');

        if (! is_bool($activationEligible)) {
            throw new InvalidArgumentException('The payment-risk activation decision is invalid.');
        }

        foreach ([
            'evaluation.held_out.overdue_f1',
            'evaluation.held_out.balanced_accuracy',
            'evaluation.held_out.brier_score',
            'evaluation.baselines.training_prevalence.overdue_f1',
            'evaluation.baselines.training_prevalence.brier_score',
            'evaluation.baselines.previous_period_late.overdue_f1',
            'evaluation.baselines.previous_period_late.brier_score',
        ] as $path) {
            $value = $this->numberAt($artifact, $path);

            if ($value < 0 || $value > 1) {
                throw new InvalidArgumentException("The payment-risk metric [{$path}] is outside its valid range.");
            }
        }

        if ($activationEligible) {
            $this->validateActivationMetadata($artifact);
        }
    }

    /**
     * @param  array<string, mixed>  $artifact
     */
    private function validateChronologicalSplit(
        array $artifact,
        CarbonImmutable $windowStart,
        CarbonImmutable $windowEnd,
        CarbonImmutable $generatedAt,
    ): void {
        foreach (['training', 'validation', 'held_out'] as $partition) {
            $this->associativeArrayAt($artifact, "training.split.{$partition}");
        }

        $trainingStart = $this->parseDate(data_get($artifact, 'training.split.training.start'), 'training.split.training.start');
        $trainingEnd = $this->parseDate(data_get($artifact, 'training.split.training.end'), 'training.split.training.end');
        $validationStart = $this->parseDate(data_get($artifact, 'training.split.validation.start'), 'training.split.validation.start');
        $validationEnd = $this->parseDate(data_get($artifact, 'training.split.validation.end'), 'training.split.validation.end');
        $heldOutStart = $this->parseDate(data_get($artifact, 'training.split.held_out.start'), 'training.split.held_out.start');
        $heldOutEnd = $this->parseDate(data_get($artifact, 'training.split.held_out.end'), 'training.split.held_out.end');
        $trainingPeriods = $this->integerAt($artifact, 'training.split.training.period_count');
        $validationPeriods = $this->integerAt($artifact, 'training.split.validation.period_count');
        $heldOutPeriods = $this->integerAt($artifact, 'training.split.held_out.period_count');
        $trainingRows = $this->integerAt($artifact, 'training.split.training.row_count');
        $validationRows = $this->integerAt($artifact, 'training.split.validation.row_count');
        $heldOutRows = $this->integerAt($artifact, 'training.split.held_out.row_count');
        $totalPeriods = $trainingPeriods + $validationPeriods + $heldOutPeriods;
        $expectedTrainingPeriods = (int) floor($totalPeriods * 0.60);
        $expectedValidationPeriods = (int) floor($totalPeriods * 0.20);

        if (
            ! $trainingStart->equalTo($windowStart)
            || ! $trainingEnd->equalTo($windowEnd)
            || $trainingEnd->greaterThanOrEqualTo($validationStart)
            || $validationStart->greaterThan($validationEnd)
            || $validationEnd->greaterThanOrEqualTo($heldOutStart)
            || $heldOutStart->greaterThan($heldOutEnd)
            || strcmp($heldOutEnd->format('Y-m'), $generatedAt->format('Y-m')) >= 0
            || min($trainingPeriods, $validationPeriods, $heldOutPeriods) < 1
            || min($trainingRows, $validationRows, $heldOutRows) < 1
            || $trainingPeriods !== $expectedTrainingPeriods
            || $validationPeriods !== $expectedValidationPeriods
            || $heldOutPeriods !== $totalPeriods - $expectedTrainingPeriods - $expectedValidationPeriods
            || $totalPeriods !== $this->integerAt($artifact, 'data_quality.distinct_period_count')
            || $trainingRows + $validationRows + $heldOutRows !== $this->integerAt($artifact, 'data_quality.valid_row_count')
        ) {
            throw new InvalidArgumentException('The payment-risk chronological split boundaries are invalid.');
        }
    }

    /** @param array<string, mixed> $artifact */
    private function validateActivationMetadata(array $artifact): void
    {
        if (data_get($artifact, 'evaluation.activation_policy_version') !== self::ACTIVATION_POLICY_VERSION) {
            throw new InvalidArgumentException('The payment-risk activation policy is incompatible.');
        }

        $selection = $this->associativeArrayAt($artifact, 'evaluation.selection');
        $selectionThreshold = $this->numberAt($artifact, 'evaluation.selection.validation_threshold');
        $minimumRecall = $this->numberAt($artifact, 'evaluation.selection.minimum_overdue_recall');
        $decisionThreshold = $this->numberAt($artifact, 'decision.threshold');

        if (
            data_get($selection, 'objective') !== self::SELECTION_OBJECTIVE
            || abs($selectionThreshold - $decisionThreshold) > 1.0E-12
            || abs($minimumRecall - self::MINIMUM_VALIDATION_RECALL) > 1.0E-12
        ) {
            throw new InvalidArgumentException('The payment-risk validation selection contract is incompatible.');
        }

        $validationRecall = $this->numberAt($artifact, 'evaluation.validation.recall_overdue');

        if ($validationRecall < 0 || $validationRecall > 1) {
            throw new InvalidArgumentException('The payment-risk validation recall is outside its valid range.');
        }

        $checks = $this->associativeArrayAt($artifact, 'evaluation.activation_checks');

        if (array_diff(self::ACTIVATION_CHECKS, array_keys($checks)) !== [] || array_diff(array_keys($checks), self::ACTIVATION_CHECKS) !== []) {
            throw new InvalidArgumentException('The payment-risk activation checks are incomplete.');
        }

        foreach ($checks as $check) {
            if (! is_bool($check)) {
                throw new InvalidArgumentException('The payment-risk activation checks must be boolean.');
            }
        }
    }

    /** @param array<string, mixed> $artifact */
    private function hasActivationSemantics(array $artifact): bool
    {
        $selectionThreshold = data_get($artifact, 'evaluation.selection.validation_threshold');
        $decisionThreshold = data_get($artifact, 'decision.threshold');

        if (
            (! is_int($selectionThreshold) && ! is_float($selectionThreshold))
            || (! is_int($decisionThreshold) && ! is_float($decisionThreshold))
            || abs((float) $selectionThreshold - (float) $decisionThreshold) > 1.0E-12
        ) {
            return false;
        }

        return data_get($artifact, 'training.source_type') === 'consented_anonymized'
            && data_get($artifact, 'data_quality.source_type') === 'consented_anonymized'
            && data_get($artifact, 'training.dataset_id') === data_get($artifact, 'data_quality.dataset_id')
            && data_get($artifact, 'training.feature_contract_version') === self::FEATURE_CONTRACT_VERSION
            && data_get($artifact, 'training.feature_names') === self::FEATURE_NAMES
            && data_get($artifact, 'training.seed') === self::RANDOM_SEED
            && data_get($artifact, 'training.split.policy') === self::SPLIT_POLICY
            && data_get($artifact, 'preprocessing.type') === 'z_score_standardization'
            && data_get($artifact, 'preprocessing.formula') === 'standardized=(value-mean)/scale'
            && data_get($artifact, 'model.type') === 'l2_logistic_regression'
            && data_get($artifact, 'model.penalty') === 'l2'
            && data_get($artifact, 'model.class_weight') === 'balanced'
            && data_get($artifact, 'model.solver') === 'liblinear'
            && data_get($artifact, 'model.positive_class') === 'overdue'
            && data_get($artifact, 'decision.positive_class') === 'overdue'
            && data_get($artifact, 'decision.negative_class') === 'on_time'
            && data_get($artifact, 'evaluation.activation_policy_version') === self::ACTIVATION_POLICY_VERSION
            && data_get($artifact, 'evaluation.selection.objective') === self::SELECTION_OBJECTIVE
            && data_get($artifact, 'evaluation.selection.minimum_overdue_recall') === self::MINIMUM_VALIDATION_RECALL
            && data_get($artifact, 'data_quality.gate_passed') === true
            && $this->matchesBooleanMap(
                data_get($artifact, 'data_quality.gates'),
                $this->expectedDataQualityGates($artifact),
            );
    }

    /** @param array<string, bool> $expected */
    private function matchesBooleanMap(mixed $actual, array $expected): bool
    {
        if (! is_array($actual) || count($actual) !== count($expected)) {
            return false;
        }

        foreach ($expected as $key => $value) {
            if (! array_key_exists($key, $actual) || $actual[$key] !== $value) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $artifact
     * @return array<string, bool>
     */
    private function expectedDataQualityGates(array $artifact): array
    {
        return [
            'minimum_rows' => $this->integerAt($artifact, 'data_quality.valid_row_count') >= $this->configuredInteger('payment-risk.minimum_valid_rows', 500),
            'minimum_distinct_members' => $this->integerAt($artifact, 'data_quality.distinct_member_count') >= $this->configuredInteger('payment-risk.minimum_member_histories', 50),
            'minimum_complete_periods' => $this->integerAt($artifact, 'data_quality.distinct_period_count') >= $this->configuredInteger('payment-risk.minimum_periods', 12),
            'minimum_on_time_class' => $this->integerAt($artifact, 'data_quality.on_time_count') >= $this->configuredInteger('payment-risk.minimum_class_count', 100),
            'minimum_overdue_class' => $this->integerAt($artifact, 'data_quality.overdue_count') >= $this->configuredInteger('payment-risk.minimum_class_count', 100),
        ];
    }

    /** @param array<string, mixed> $artifact */
    private function validateExplanations(array $artifact): void
    {
        $factors = data_get($artifact, 'explanation.factors');

        if (! is_array($factors) || ! array_is_list($factors) || count($factors) !== count(self::FEATURE_NAMES)) {
            throw new InvalidArgumentException('The payment-risk explanation schema is invalid.');
        }

        foreach ($factors as $index => $factor) {
            if (! is_array($factor) || data_get($factor, 'feature') !== self::FEATURE_NAMES[$index]) {
                throw new InvalidArgumentException('The payment-risk explanation features are out of order.');
            }

            $label = data_get($factor, 'label');

            if (! is_string($label) || trim($label) === '') {
                throw new InvalidArgumentException('A payment-risk explanation label is missing.');
            }

            $coefficient = data_get($factor, 'coefficient');

            if ((! is_int($coefficient) && ! is_float($coefficient)) || ! is_finite((float) $coefficient)) {
                throw new InvalidArgumentException('A payment-risk explanation coefficient is invalid.');
            }

            if (! in_array(data_get($factor, 'sign'), ['increases_overdue_risk', 'reduces_overdue_risk', 'neutral'], true)) {
                throw new InvalidArgumentException('A payment-risk explanation sign is invalid.');
            }
        }
    }

    /** @param array<string, mixed> $artifact */
    private function numericVector(array $artifact, string $path, bool $allowZero): void
    {
        $values = data_get($artifact, $path);

        if (! is_array($values) || ! array_is_list($values) || count($values) !== count(self::FEATURE_NAMES)) {
            throw new InvalidArgumentException("The payment-risk vector [{$path}] has an invalid length.");
        }

        foreach ($values as $value) {
            if (
                (! is_int($value) && ! is_float($value))
                || ! is_finite((float) $value)
                || (! $allowZero && $value <= 0)
            ) {
                throw new InvalidArgumentException("The payment-risk vector [{$path}] contains an invalid value.");
            }
        }
    }

    /** @param array<string, mixed> $artifact */
    public function numberAt(array $artifact, string $path): float
    {
        $value = data_get($artifact, $path);

        if ((! is_int($value) && ! is_float($value)) || ! is_finite((float) $value)) {
            throw new InvalidArgumentException("The payment-risk numeric field [{$path}] is invalid.");
        }

        return (float) $value;
    }

    /** @param array<string, mixed> $artifact */
    public function stringAt(array $artifact, string $path): string
    {
        $value = data_get($artifact, $path);

        if (! is_string($value)) {
            throw new InvalidArgumentException("The payment-risk string field [{$path}] is invalid.");
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $artifact
     * @return array<string, mixed>
     */
    public function associativeArrayAt(array $artifact, string $path): array
    {
        return $this->associativeArray(data_get($artifact, $path), $path);
    }

    /** @param array<string, mixed> $artifact */
    private function integerAt(array $artifact, string $path): int
    {
        $value = data_get($artifact, $path);

        if (! is_int($value)) {
            throw new InvalidArgumentException("The payment-risk integer field [{$path}] is invalid.");
        }

        return $value;
    }

    /**
     * @return array<string, mixed>
     */
    private function associativeArray(mixed $value, string $field): array
    {
        if (! is_array($value) || array_is_list($value)) {
            throw new InvalidArgumentException("The payment-risk artifact [{$field}] must be a JSON object.");
        }

        $result = [];

        foreach ($value as $key => $item) {
            if (! is_string($key)) {
                throw new InvalidArgumentException("The payment-risk artifact [{$field}] has an invalid object key.");
            }

            $result[$key] = $item;
        }

        return $result;
    }

    private function configuredInteger(string $key, int $default): int
    {
        $value = filter_var(config($key, $default), FILTER_VALIDATE_INT);

        return is_int($value) ? $value : $default;
    }

    private function parseDate(mixed $value, string $field): CarbonImmutable
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            throw new InvalidArgumentException("The payment-risk date [{$field}] is invalid.");
        }

        $date = CarbonImmutable::createFromFormat('!Y-m-d', $value);

        if (! $date instanceof CarbonImmutable || $date->format('Y-m-d') !== $value) {
            throw new InvalidArgumentException("The payment-risk date [{$field}] is invalid.");
        }

        return $date;
    }

    private function parseDateTime(mixed $value, string $field): CarbonImmutable
    {
        $matches = [];

        if (
            ! is_string($value)
            || preg_match(
                '/^(?<year>\d{4})-(?<month>\d{2})-(?<day>\d{2})T(?<hour>\d{2}):(?<minute>\d{2}):(?<second>\d{2})(?:\.\d{1,6})?(?:Z|(?<offset_sign>[+-])(?<offset_hour>\d{2}):(?<offset_minute>\d{2}))$/',
                $value,
                $matches,
            ) !== 1
        ) {
            throw new InvalidArgumentException("The payment-risk timestamp [{$field}] is invalid.");
        }

        $offsetHour = (int) ($matches['offset_hour'] ?? 0);
        $offsetMinute = (int) ($matches['offset_minute'] ?? 0);

        if (
            ! checkdate((int) $matches['month'], (int) $matches['day'], (int) $matches['year'])
            || (int) $matches['hour'] > 23
            || (int) $matches['minute'] > 59
            || (int) $matches['second'] > 59
            || $offsetHour > 14
            || $offsetMinute > 59
            || ($offsetHour === 14 && $offsetMinute !== 0)
        ) {
            throw new InvalidArgumentException("The payment-risk timestamp [{$field}] is invalid.");
        }

        return CarbonImmutable::parse($value);
    }
}
