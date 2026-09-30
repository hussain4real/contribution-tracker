<?php

declare(strict_types=1);

return [
    'artifact_disk' => env('PAYMENT_RISK_ARTIFACT_DISK', 'local'),
    'artifact_directory' => 'payment-risk/models',
    'max_artifact_bytes' => 2 * 1024 * 1024,
    'max_model_age_days' => (int) env('PAYMENT_RISK_MODEL_MAX_AGE_DAYS', 365),
    'minimum_days_before_due' => 7,
    'minimum_valid_rows' => 500,
    'minimum_member_histories' => 50,
    'minimum_periods' => 12,
    'minimum_class_count' => 100,
];
