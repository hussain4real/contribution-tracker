<?php

declare(strict_types=1);

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
        $owner = $this->stringConfig('services.github.releases.owner');
        $repo = $this->stringConfig('services.github.releases.repo');
        $freshTtl = $this->integerConfig('services.github.releases.cache_fresh_ttl', 600);
        $staleTtl = $this->integerConfig('services.github.releases.cache_stale_ttl', 3600);

        $releases = Cache::flexible('github_releases', [$freshTtl, $staleTtl], function () use ($owner, $repo): array {
            if (blank($owner) || blank($repo)) {
                return [];
            }

            $token = $this->stringConfig('services.github.releases.token');
            $max = $this->integerConfig('services.github.releases.max', 50);

            $request = Http::withHeaders([
                'Accept' => 'application/vnd.github+json',
                'X-GitHub-Api-Version' => '2022-11-28',
            ])
                ->connectTimeout(2)
                ->timeout(5);

            if ($token !== '') {
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

            $payload = $response->json();

            if (! is_array($payload)) {
                return [];
            }

            return $this->mapReleasePayload(
                payload: $payload,
                includePrereleases: (bool) config('services.github.releases.include_prereleases', true),
                includeBody: (bool) config('services.github.releases.include_body', true),
            );
        });

        return $releases;
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

    private function stringConfig(string $key): string
    {
        $value = config($key);

        return is_string($value) ? $value : '';
    }

    private function integerConfig(string $key, int $default): int
    {
        $value = config($key, $default);

        return is_numeric($value) ? (int) $value : $default;
    }

    /**
     * @param  array<int|string, mixed>  $payload
     * @return list<array{id: int, name: string, tag_name: string, body: string, html_url: string, published_at: string, prerelease: bool}>
     */
    private function mapReleasePayload(array $payload, bool $includePrereleases, bool $includeBody): array
    {
        $releases = [];

        foreach ($payload as $release) {
            if (! is_array($release)) {
                continue;
            }

            $id = $release['id'] ?? null;
            $tagName = $release['tag_name'] ?? null;
            $htmlUrl = $release['html_url'] ?? null;
            $publishedAt = $release['published_at'] ?? null;
            $prerelease = ($release['prerelease'] ?? false) === true;

            if (! $includePrereleases && $prerelease) {
                continue;
            }

            if (! is_numeric($id) || ! is_string($tagName) || ! is_string($htmlUrl) || ! is_string($publishedAt)) {
                continue;
            }

            $name = $release['name'] ?? $tagName;
            $body = $release['body'] ?? '';

            $releases[] = [
                'id' => (int) $id,
                'name' => is_string($name) && $name !== '' ? $name : $tagName,
                'tag_name' => $tagName,
                'body' => $includeBody && is_string($body) ? Str::markdown($body, [
                    'html_input' => 'strip',
                    'allow_unsafe_links' => false,
                ]) : '',
                'html_url' => $htmlUrl,
                'published_at' => $publishedAt,
                'prerelease' => $prerelease,
            ];
        }

        return $releases;
    }
}
