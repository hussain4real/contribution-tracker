<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'facebook' => [
        'app_id' => env('FACEBOOK_APP_ID'),
    ],

    'github' => [
        'releases' => [
            'owner' => env('GITHUB_RELEASES_OWNER'),
            'repo' => env('GITHUB_RELEASES_REPO'),
            'token' => env('GITHUB_RELEASES_TOKEN'),
            'cache_fresh_ttl' => (int) env('GITHUB_RELEASES_CACHE_FRESH_TTL', 600),
            'cache_stale_ttl' => (int) env('GITHUB_RELEASES_CACHE_STALE_TTL', 3600),
            'include_prereleases' => (bool) env('GITHUB_RELEASES_INCLUDE_PRERELEASES', true),
            'max' => (int) env('GITHUB_RELEASES_MAX', 50),
            'include_body' => (bool) env('GITHUB_RELEASES_INCLUDE_BODY', true),
        ],
    ],

];
