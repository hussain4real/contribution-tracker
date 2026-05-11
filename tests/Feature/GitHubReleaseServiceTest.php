<?php

use App\Models\User;
use App\Services\GitHubReleaseService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Cache::forget('github_releases');
    config([
        'services.github.releases.owner' => 'laravel',
        'services.github.releases.repo' => 'framework',
        'services.github.releases.token' => null,
        'services.github.releases.max' => 2,
        'services.github.releases.include_prereleases' => false,
        'services.github.releases.include_body' => true,
    ]);

    Http::preventStrayRequests();
});

it('returns no releases when repository configuration is missing', function () {
    config(['services.github.releases.owner' => null]);

    expect(app(GitHubReleaseService::class)->releases())->toBe([]);
});

it('maps releases and filters prereleases', function () {
    Http::fake([
        'api.github.com/repos/laravel/framework/releases*' => Http::response([
            [
                'id' => 10,
                'name' => null,
                'tag_name' => 'v1.0.0',
                'body' => '**Hello**',
                'html_url' => 'https://github.com/laravel/framework/releases/tag/v1.0.0',
                'published_at' => '2026-05-11T00:00:00Z',
                'prerelease' => false,
            ],
            [
                'id' => 11,
                'name' => 'Beta',
                'tag_name' => 'v1.1.0-beta',
                'body' => 'Beta',
                'html_url' => 'https://github.com/laravel/framework/releases/tag/v1.1.0-beta',
                'published_at' => '2026-05-12T00:00:00Z',
                'prerelease' => true,
            ],
        ]),
    ]);

    $releases = app(GitHubReleaseService::class)->releases();

    expect($releases)->toHaveCount(1)
        ->and($releases[0]['name'])->toBe('v1.0.0')
        ->and($releases[0]['body'])->toContain('<strong>Hello</strong>');
});

it('adds an authorization token and can omit release bodies', function () {
    config([
        'services.github.releases.token' => 'ghp_token',
        'services.github.releases.include_prereleases' => true,
        'services.github.releases.include_body' => false,
    ]);

    Http::fake([
        'api.github.com/repos/laravel/framework/releases*' => Http::response([[
            'id' => 20,
            'name' => 'Release',
            'tag_name' => 'v2.0.0',
            'body' => '**Hidden**',
            'html_url' => 'https://github.com/laravel/framework/releases/tag/v2.0.0',
            'published_at' => '2026-05-14T00:00:00Z',
            'prerelease' => true,
        ]]),
    ]);

    $releases = app(GitHubReleaseService::class)->releases();

    expect($releases[0]['body'])->toBe('');

    Http::assertSent(fn ($request): bool => $request->hasHeader('Authorization', 'Bearer ghp_token'));
});

it('returns no releases on github failures and connection errors', function () {
    Http::fake([
        'api.github.com/repos/laravel/framework/releases*' => Http::response(['message' => 'Nope'], 500),
    ]);

    expect(app(GitHubReleaseService::class)->releases())->toBe([]);

    Cache::forget('github_releases');
    Http::fake([
        'api.github.com/repos/laravel/framework/releases*' => Http::failedConnection(),
    ]);

    expect(app(GitHubReleaseService::class)->releases())->toBe([]);
});

it('marks the latest release as seen when one exists', function () {
    Http::fake([
        'api.github.com/repos/laravel/framework/releases*' => Http::response([[
            'id' => 15,
            'name' => 'Release',
            'tag_name' => 'v1.2.0',
            'body' => '',
            'html_url' => 'https://github.com/laravel/framework/releases/tag/v1.2.0',
            'published_at' => '2026-05-13T00:00:00Z',
            'prerelease' => false,
        ]]),
    ]);
    $user = User::factory()->create(['last_seen_changelog_release_id' => null]);
    $service = app(GitHubReleaseService::class);

    expect($service->updateDataFor($user))->toHaveKey('unseen', true);

    $service->markLatestSeen($user);

    expect($user->refresh()->last_seen_changelog_release_id)->toBe(15);
});

it('does not mark a changelog release as seen when there is no latest release', function () {
    config(['services.github.releases.owner' => null]);
    $user = User::factory()->create(['last_seen_changelog_release_id' => null]);

    app(GitHubReleaseService::class)->markLatestSeen($user);

    expect($user->refresh()->last_seen_changelog_release_id)->toBeNull();
});
