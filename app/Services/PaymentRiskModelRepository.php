<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\PaymentRiskModelVersion;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

final class PaymentRiskModelRepository
{
    public function __construct(private readonly PaymentRiskArtifactValidator $validator) {}

    /**
     * @return array{model: PaymentRiskModelVersion, artifact: array<string, mixed>}
     */
    public function active(): array
    {
        $models = PaymentRiskModelVersion::query()
            ->where('is_active', true)
            ->latest('activated_at')
            ->limit(2)
            ->get();

        if ($models->isEmpty()) {
            throw new RuntimeException('No active payment-risk model is installed.');
        }

        if ($models->count() !== 1) {
            throw new RuntimeException('Multiple active payment-risk models were found; scoring is disabled until the ambiguity is resolved.');
        }

        $model = $models->firstOrFail();

        if (! $model->activation_eligible) {
            throw new RuntimeException('The active payment-risk model is not activation-eligible.');
        }

        $disk = Storage::disk($model->artifact_disk);

        if (! $disk->exists($model->artifact_path)) {
            throw new RuntimeException('The active payment-risk artifact is missing.');
        }

        $json = $disk->get($model->artifact_path);

        if (! is_string($json) || ! hash_equals($model->artifact_sha256, hash('sha256', $json))) {
            throw new RuntimeException('The active payment-risk artifact checksum is invalid.');
        }

        try {
            $artifact = $this->validator->validateJson($json);
        } catch (\Throwable $exception) {
            throw new RuntimeException('The active payment-risk artifact is corrupt or incompatible.', previous: $exception);
        }

        $artifactThreshold = $this->validator->numberAt($artifact, 'decision.threshold');
        $artifactTrainingStart = $this->validator->stringAt($artifact, 'training.window.start');
        $artifactTrainingEnd = $this->validator->stringAt($artifact, 'training.window.end');
        $artifactTrainedAt = CarbonImmutable::parse($this->validator->stringAt($artifact, 'generated_at'));
        $artifactDatasetSummary = $this->validator->associativeArrayAt($artifact, 'data_quality');
        $artifactDatasetSummary['training_overdue_prevalence'] = $this->validator->numberAt(
            $artifact,
            'training.overdue_prevalence',
        );
        $artifactMetrics = $this->validator->associativeArrayAt($artifact, 'evaluation');

        if (
            data_get($artifact, 'model_version') !== $model->version
            || data_get($artifact, 'artifact_schema_version') !== $model->schema_version
            || data_get($artifact, 'training.feature_names') !== $model->feature_schema
            || abs($artifactThreshold - $model->threshold) > 1.0E-12
            || $artifactTrainingStart !== $model->training_window_start->toDateString()
            || $artifactTrainingEnd !== $model->training_window_end->toDateString()
            || $artifactTrainedAt->getTimestamp() !== $model->trained_at->getTimestamp()
            || $this->normalizedMetadata($artifactDatasetSummary) !== $this->normalizedMetadata($model->dataset_summary)
            || $this->normalizedMetadata($artifactMetrics) !== $this->normalizedMetadata($model->metrics)
            || ! $this->validator->isActivationEligible($artifact)
        ) {
            throw new RuntimeException('The active payment-risk artifact does not match its installed metadata.');
        }

        $maxAgeDays = config('payment-risk.max_model_age_days', 365);
        $maxAgeDays = is_numeric($maxAgeDays) ? max(1, (int) $maxAgeDays) : 365;
        $trainingEnd = CarbonImmutable::parse($model->training_window_end->toDateString())->endOfDay();

        if ($trainingEnd->lessThan(now()->subDays($maxAgeDays))) {
            throw new RuntimeException('The active payment-risk model is stale and must be retrained.');
        }

        return ['model' => $model, 'artifact' => $artifact];
    }

    private function normalizedMetadata(mixed $value): mixed
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        if (! is_array($value)) {
            return $value;
        }

        $normalized = [];

        foreach ($value as $key => $item) {
            $normalized[$key] = $this->normalizedMetadata($item);
        }

        if (! array_is_list($normalized)) {
            ksort($normalized);
        }

        return $normalized;
    }
}
