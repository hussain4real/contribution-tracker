<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\PaymentRiskModelVersion;
use App\Services\PaymentRiskArtifactValidator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use RuntimeException;

final class InstallPaymentRiskModel
{
    public function __construct(private readonly PaymentRiskArtifactValidator $validator) {}

    public function handle(string $artifactPath, bool $activate = false): PaymentRiskModelVersion
    {
        $resolvedPath = realpath($artifactPath);

        if ($resolvedPath === false || ! is_file($resolvedPath) || ! is_readable($resolvedPath)) {
            throw new InvalidArgumentException('The payment-risk artifact does not exist or is not readable.');
        }

        $json = file_get_contents($resolvedPath) ?: throw new InvalidArgumentException('The payment-risk artifact could not be read.');

        $artifact = $this->validator->validateJson($json);
        $version = $this->validator->stringAt($artifact, 'model_version');
        $checksum = $this->verifiedManifestChecksum($resolvedPath, $json);
        $diskName = config('payment-risk.artifact_disk', 'local');
        $directory = config('payment-risk.artifact_directory', 'payment-risk/models');
        $diskName = is_string($diskName) && $diskName !== '' ? $diskName : 'local';
        $directory = is_string($directory) && $directory !== '' ? trim($directory, '/') : 'payment-risk/models';

        if ($diskName === 'public' || config("filesystems.disks.{$diskName}.visibility") === 'public') {
            throw new InvalidArgumentException('Payment-risk artifacts must use a private filesystem disk.');
        }

        $existingModel = PaymentRiskModelVersion::query()->where('version', $version)->first();

        if ($existingModel instanceof PaymentRiskModelVersion) {
            $this->assertMatchingChecksum($existingModel, $checksum);
        }

        $storedPath = $existingModel instanceof PaymentRiskModelVersion
            ? $existingModel->artifact_path
            : "{$directory}/{$version}/{$checksum}.json";

        $activationEligible = $this->validator->isActivationEligible($artifact);
        $trainingWindowStart = $this->validator->stringAt($artifact, 'training.window.start');
        $trainingWindowEnd = $this->validator->stringAt($artifact, 'training.window.end');
        $trainedAt = $this->validator->stringAt($artifact, 'generated_at');

        if (! Storage::disk($diskName)->put($storedPath, $json)) {
            throw new RuntimeException('The payment-risk artifact could not be copied to private storage.');
        }

        $model = DB::transaction(function () use (
            $activationEligible,
            $artifact,
            $checksum,
            $diskName,
            $storedPath,
            $trainedAt,
            $trainingWindowEnd,
            $trainingWindowStart,
            $version,
        ): PaymentRiskModelVersion {
            $existing = PaymentRiskModelVersion::query()
                ->where('version', $version)
                ->lockForUpdate()
                ->first();

            if ($existing instanceof PaymentRiskModelVersion) {
                $this->assertMatchingChecksum($existing, $checksum);
                $model = $existing;
            } else {
                $datasetSummary = $this->validator->associativeArrayAt($artifact, 'data_quality');
                $evaluation = $this->validator->associativeArrayAt($artifact, 'evaluation');

                $datasetSummary['training_overdue_prevalence'] = data_get($artifact, 'training.overdue_prevalence');

                $model = PaymentRiskModelVersion::query()->create([
                    'version' => $version,
                    'schema_version' => $this->validator->stringAt($artifact, 'artifact_schema_version'),
                    'artifact_disk' => $diskName,
                    'artifact_path' => $storedPath,
                    'artifact_sha256' => $checksum,
                    'feature_schema' => data_get($artifact, 'training.feature_names'),
                    'threshold' => $this->validator->numberAt($artifact, 'decision.threshold'),
                    'training_window_start' => $trainingWindowStart,
                    'training_window_end' => $trainingWindowEnd,
                    'dataset_summary' => $datasetSummary,
                    'metrics' => $evaluation,
                    'activation_eligible' => $activationEligible,
                    'is_active' => false,
                    'trained_at' => CarbonImmutable::parse($trainedAt),
                ]);
            }

            return $model;
        }, 3);

        return $activate ? $this->activate($model) : $model;
    }

    private function verifiedManifestChecksum(string $resolvedPath, string $json): string
    {
        if (basename($resolvedPath) !== 'model.json') {
            throw new InvalidArgumentException('The payment-risk artifact must be the model.json file emitted by the training pipeline.');
        }

        $manifestPath = dirname($resolvedPath).'/checksums.sha256';
        $manifest = is_file($manifestPath) && is_readable($manifestPath)
            ? file_get_contents($manifestPath)
            : false;

        if (! is_string($manifest) || trim($manifest) === '') {
            throw new InvalidArgumentException('The payment-risk checksum manifest is missing or unreadable.');
        }

        $lines = preg_split('/\r\n|\r|\n/', trim($manifest)) ?: [];
        $modelEntries = array_values(array_filter($lines, fn (string $line): bool => str_ends_with($line, '  model.json')));
        $matches = [];
        $hasExactEntry = count($modelEntries) === 1
            && preg_match('/^([0-9a-f]{64})  model\.json$/', $modelEntries[0], $matches) === 1;
        $checksum = hash('sha256', $json);

        if (! $hasExactEntry || ! hash_equals($matches[1], $checksum)) {
            throw new InvalidArgumentException('The payment-risk model.json checksum does not match checksums.sha256.');
        }

        return $checksum;
    }

    private function assertMatchingChecksum(PaymentRiskModelVersion $model, string $checksum): void
    {
        if (! hash_equals($model->artifact_sha256, $checksum)) {
            throw new InvalidArgumentException('The model version is already installed with a different checksum.');
        }
    }

    private function activate(PaymentRiskModelVersion $model): PaymentRiskModelVersion
    {
        if (! $model->activation_eligible) {
            throw new InvalidArgumentException('This payment-risk model did not pass the activation gates. It remains installed but inactive.');
        }

        $this->assertFresh($model);

        return DB::transaction(function () use ($model): PaymentRiskModelVersion {
            PaymentRiskModelVersion::query()->lockForUpdate()->get(['id']);
            DB::table('payment_risk_model_versions')
                ->where('is_active', true)
                ->where('id', '!=', $model->id)
                ->update([
                    'is_active' => false,
                    'deactivated_at' => now(),
                    'updated_at' => now(),
                ]);
            DB::table('payment_risk_model_versions')
                ->where('id', $model->id)
                ->update([
                    'is_active' => true,
                    'activated_at' => now(),
                    'deactivated_at' => null,
                    'updated_at' => now(),
                ]);

            return $model->refresh();
        }, 3);
    }

    private function assertFresh(PaymentRiskModelVersion $model): void
    {
        $maxAgeDays = config('payment-risk.max_model_age_days', 365);
        $maxAgeDays = is_numeric($maxAgeDays) ? max(1, (int) $maxAgeDays) : 365;

        if ($model->training_window_end->endOfDay()->lessThan(now()->subDays($maxAgeDays))) {
            throw new InvalidArgumentException('This payment-risk model is stale and cannot be activated.');
        }
    }
}
