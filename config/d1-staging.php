<?php

declare(strict_types=1);

return [
    'live_proof' => (bool) env('D1_STAGING_LIVE_PROOF', false),
    'enabled' => (bool) env('D1_STAGING_ENABLED', false),
    'startup_profile' => (bool) env('FF_STARTUP_PROFILE', false),
    'read_only_dashboard' => (bool) env('D1_STAGING_READ_ONLY_DASHBOARD', false),
    'read_snapshot_cache' => (bool) env('D1_STAGING_READ_SNAPSHOT_CACHE', false),
    'read_snapshot_path' => storage_path('app/private/d1-read-snapshots'),
    'database' => env('D1_STAGING_DATABASE', 'familyfunds-d1-web-staging'),
    'url' => env('D1_STAGING_URL', ''),
    'delivery' => [
        'enabled' => (bool) env('D1_STAGING_DELIVERY_ENABLED', false),
        'email' => env('D1_STAGING_DELIVERY_EMAIL', ''),
        'whatsapp' => env('D1_STAGING_DELIVERY_WHATSAPP', ''),
        'whatsapp_token' => env('D1_STAGING_DELIVERY_WHATSAPP_TOKEN', ''),
    ],
    'r2' => [
        'bucket' => env('D1_STAGING_R2_BUCKET', ''),
        'key' => env('D1_STAGING_R2_ACCESS_KEY_ID', ''),
        'secret' => env('D1_STAGING_R2_SECRET_ACCESS_KEY', ''),
    ],
    'token' => env('D1_STAGING_TOKEN', ''),
    'webhooks' => [
        'enabled' => (bool) env('D1_STAGING_WEBHOOKS_ENABLED', false),
        'relay_token' => env('D1_STAGING_RELAY_TOKEN', ''),
        'paystack_test_key' => env('D1_STAGING_PAYSTACK_TEST_KEY', ''),
        'meta_app_secret' => env('D1_STAGING_META_APP_SECRET', ''),
    ],
];
