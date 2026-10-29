<?php

declare(strict_types=1);

return [
    'enabled' => (bool) env('D1_PRODUCTION_ENABLED', false),
    'delivery_enabled' => (bool) env('D1_PRODUCTION_DELIVERY_ENABLED', false),
    'webhooks_enabled' => (bool) env('D1_PRODUCTION_WEBHOOKS_ENABLED', false),
    'migration_checks' => (bool) env('D1_MIGRATION_CHECKS', false),
    'queue_worker_enabled' => (bool) env('QUEUE_WORKER_ENABLED', false),
    'provider_read_only_checks' => (bool) env('D1_READ_ONLY_PROVIDER_CHECKS', false),
    'database' => env('D1_DATABASE', 'familyfunds-production'),
    'url' => env('D1_URL', 'https://familyfunds-production.d1.internal'),
    'token' => env('D1_TOKEN', ''),
    'read_only_dashboard' => true,
    'read_snapshot_cache' => true,
    'read_snapshot_path' => storage_path('app/private/d1-production-read-snapshots'),
    'startup_profile' => (bool) env('D1_STARTUP_PROFILE', false),
    'bank_lookup_test_key' => env('D1_CANDIDATE_BANK_LOOKUP_TEST_KEY', ''),
    'relay_token' => env('WEBHOOK_RELAY_TOKEN', ''),
    'r2' => [
        'bucket' => env('R2_BUCKET', 'familyfunds-production-private'),
        'key' => env('R2_ACCESS_KEY_ID', ''),
        'secret' => env('R2_SECRET_ACCESS_KEY', ''),
    ],
];
