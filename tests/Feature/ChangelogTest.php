<?php

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->user = User::factory()->member()->employed()->create();
    Cache::forget('github_releases');
});

it('requires authentication to access the changelog', function () {
    $this->get('/changelog')
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
        [
            'id' => 1,
            'name' => 'v1.0.0',
            'tag_name' => 'v1.0.0',
            'body' => '## What\'s Changed\n- Initial release',
            'html_url' => 'https://github.com/test/repo/releases/tag/v1.0.0',
            'published_at' => '2026-03-01T00:00:00Z',
            'created_at' => '2026-03-01T00:00:00Z',
            'prerelease' => false,
        ],
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
        );
});

it('caches github releases', function () {
    Http::fake([
        'api.github.com/*' => Http::response([
            [
                'id' => 1,
                'name' => 'v1.0.0',
                'tag_name' => 'v1.0.0',
                'body' => 'Test',
                'html_url' => 'https://github.com/test/repo/releases/tag/v1.0.0',
                'published_at' => '2026-03-01T00:00:00Z',
                'created_at' => '2026-03-01T00:00:00Z',
                'prerelease' => false,
            ],
        ], 200),
    ]);

    expect(Cache::has('github_releases'))->toBeFalse();

    $this->actingAs($this->user)->get('/changelog');

    expect(Cache::has('github_releases'))->toBeTrue();
    expect(Cache::get('github_releases'))->toHaveCount(1);
});
