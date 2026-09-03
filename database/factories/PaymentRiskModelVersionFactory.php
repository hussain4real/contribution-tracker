<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\PaymentRiskModelVersion;
use App\Services\PaymentRiskArtifactValidator;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<PaymentRiskModelVersion> */
class PaymentRiskModelVersionFactory extends Factory
{
    /** @return array<model-property<PaymentRiskModelVersion>, mixed> */
    public function definition(): array
    {
        $version = 'test-'.Str::lower(Str::random(12));

        return [
            'version' => $version,
            'schema_version' => PaymentRiskArtifactValidator::SCHEMA_VERSION,
            'artifact_disk' => 'local',
            'artifact_path' => "payment-risk/models/{$version}/model.json",
            'artifact_sha256' => hash('sha256', $version),
            'feature_schema' => PaymentRiskArtifactValidator::FEATURE_NAMES,
            'threshold' => 0.5,
            'training_window_start' => now()->subYear()->startOfMonth(),
            'training_window_end' => now()->subMonth()->startOfMonth(),
            'dataset_summary' => [
                'valid_row_count' => 600,
                'distinct_member_count' => 60,
                'distinct_period_count' => 12,
                'on_time_count' => 350,
                'overdue_count' => 250,
            ],
            'metrics' => ['overdue_f1' => 0.72, 'balanced_accuracy' => 0.68, 'brier_score' => 0.18],
            'activation_eligible' => true,
            'is_active' => false,
            'trained_at' => now(),
            'activated_at' => null,
            'deactivated_at' => null,
        ];
    }
}
