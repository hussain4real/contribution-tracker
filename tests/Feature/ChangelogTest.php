<?php

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->user = User::factory()->member()->employed()->create();
    Cache::forget('github_releases');
});

function githubRelease(array $overrides = []): array
{
    return array_merge([
        'id' => 1,
        'name' => 'v1.0.0',
        'tag_name' => 'v1.0.0',
        'body' => '## What\'s Changed\n- Initial release',
        'html_url' => 'https://github.com/test/repo/releases/tag/v1.0.0',
        'published_at' => '2026-03-01T00:00:00Z',
        'created_at' => '2026-03-01T00:00:00Z',
        'prerelease' => false,
    ], $overrides);
}

it('requires authentication to access the changelog', function () {
    $this->get('/changelog')
        ->assertRedirect();
});

it('requires authentication to mark changelog updates as seen', function () {
    $this->post(route('changelog.seen'))
        ->assertRedirect();
});

it('displays the changelog page for authenticated users', function () {
    Http::fake([
        'api.github.com/*' => Http::response([], 200),
    ]);

    $this->actingAs($this->user)
        ->get('/changelog')
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->component('Changelog/Index')
            ->has('releases')
        );
});

it('fetches and displays github releases', function () {
    $fakeReleases = [
        githubRelease(),
    ];

    Http::fake([
        'api.github.com/*' => Http::response($fakeReleases, 200),
    ]);

    $this->actingAs($this->user)
        ->get('/changelog')
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->component('Changelog/Index')
            ->has('releases', 1)
            ->where('releases.0.name', 'v1.0.0')
            ->where('releases.0.tag_name', 'v1.0.0')
        );
});

it('shares latest changelog update data with authenticated pages', function () {
    Http::fake([
        'api.github.com/*' => Http::response([githubRelease()], 200),
    ]);

    $this->actingAs($this->user)
        ->get('/changelog')
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->where('changelogUpdate.latest.id', 1)
            ->where('changelogUpdate.latest.name', 'v1.0.0')
            ->where('changelogUpdate.latest.tag_name', 'v1.0.0')
            ->where('changelogUpdate.unseen', true)
        );
});

it('marks the latest changelog release as seen for the current user', function () {
    Http::fake([
        'api.github.com/*' => Http::response([githubRelease()], 200),
    ]);

    $this->actingAs($this->user)
        ->post(route('changelog.seen'))
        ->assertRedirect();

    expect($this->user->fresh()->last_seen_changelog_release_id)->toBe(1);
});

it('does not mark latest changelog update as unseen after it has been seen', function () {
    $this->user->update(['last_seen_changelog_release_id' => 1]);

    Http::fake([
        'api.github.com/*' => Http::response([githubRelease()], 200),
    ]);

    $this->actingAs($this->user)
        ->get('/changelog')
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->where('changelogUpdate.latest.id', 1)
            ->where('changelogUpdate.unseen', false)
        );
});

it('handles github api failure gracefully', function () {
    Http::fake([
        'api.github.com/*' => Http::response([], 500),
    ]);

    $this->actingAs($this->user)
        ->get('/changelog')
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->component('Changelog/Index')
            ->has('releases', 0)
            ->where('changelogUpdate.latest', null)
            ->where('changelogUpdate.unseen', false)
        );
});

it('caches github releases with stale-while-revalidate', function () {
    Http::fake([
        'api.github.com/*' => Http::response([
            githubRelease(['body' => 'Test']),
        ], 200),
    ]);

    // First request populates the cache
    $this->actingAs($this->user)->get('/changelog');

    expect(Cache::has('github_releases'))->toBeTrue();
    expect(Cache::get('github_releases'))->toHaveCount(1);

    // Second request should serve from cache without hitting the API again
    Http::fake([
        'api.github.com/*' => Http::response([], 500),
    ]);

    $this->actingAs($this->user)
        ->get('/changelog')
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->has('releases', 1)
            ->where('releases.0.name', 'v1.0.0')
        );
});
