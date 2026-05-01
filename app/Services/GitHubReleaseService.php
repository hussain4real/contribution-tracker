<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

class GitHubReleaseService
{
    /**
     * Get cached GitHub releases for the app changelog.
     *
     * @return list<array{id: int, name: string, tag_name: string, body: string, html_url: string, published_at: string, prerelease: bool}>
     */
    public function releases(): array
    {
        $owner = config('services.github.releases.owner');
        $repo = config('services.github.releases.repo');
        $freshTtl = config('services.github.releases.cache_fresh_ttl', 600);
        $staleTtl = config('services.github.releases.cache_stale_ttl', 3600);

        return Cache::flexible('github_releases', [$freshTtl, $staleTtl], function () use ($owner, $repo) {
            if (blank($owner) || blank($repo)) {
                return [];
            }

            $token = config('services.github.releases.token');
            $max = config('services.github.releases.max', 50);

            $request = Http::withHeaders([
                'Accept' => 'application/vnd.github+json',
                'X-GitHub-Api-Version' => '2022-11-28',
            ])
                ->connectTimeout(2)
                ->timeout(5);

            if ($token) {
                $request = $request->withToken($token);
            }

            try {
                $response = $request->get("https://api.github.com/repos/{$owner}/{$repo}/releases", [
                    'per_page' => $max,
                ]);
            } catch (Throwable) {
                return [];
            }

            if ($response->failed()) {
                return [];
            }

            $includePrereleases = config('services.github.releases.include_prereleases', true);
            $includeBody = config('services.github.releases.include_body', true);

            return collect($response->json())
                ->when(! $includePrereleases, fn ($collection) => $collection->where('prerelease', false))
                ->map(fn (array $release) => [
                    'id' => $release['id'],
                    'name' => $release['name'] ?? $release['tag_name'],
                    'tag_name' => $release['tag_name'],
                    'body' => $includeBody ? Str::markdown($release['body'] ?? '', [
                        'html_input' => 'strip',
                        'allow_unsafe_links' => false,
                    ]) : '',
                    'html_url' => $release['html_url'],
                    'published_at' => $release['published_at'],
                    'prerelease' => $release['prerelease'] ?? false,
                ])
                ->values()
                ->toArray();
        });
    }

    /**
     * Get the latest mapped GitHub release.
     *
     * @return array{id: int, name: string, tag_name: string, body: string, html_url: string, published_at: string, prerelease: bool}|null
     */
    public function latest(): ?array
    {
        return $this->releases()[0] ?? null;
    }

    /**
     * Get safe shared update data for an authenticated user.
     *
     * @return array{latest: array{id: int, name: string, tag_name: string, body: string, html_url: string, published_at: string, prerelease: bool}|null, unseen: bool}
     */
    public function updateDataFor(User $user): array
    {
        $latest = $this->latest();

        return [
            'latest' => $latest,
            'unseen' => $latest !== null && $user->last_seen_changelog_release_id !== $latest['id'],
        ];
    }

    public function markLatestSeen(User $user): void
    {
        $latest = $this->latest();

        if ($latest === null) {
            return;
        }

        $user->forceFill([
            'last_seen_changelog_release_id' => $latest['id'],
        ])->save();
    }
}
