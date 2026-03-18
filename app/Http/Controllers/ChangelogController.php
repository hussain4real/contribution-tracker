<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class ChangelogController extends Controller
{
    public function __invoke(): Response
    {
        $owner = config('services.github.releases.owner');
        $repo = config('services.github.releases.repo');
        $cacheTtl = config('services.github.releases.cache_ttl', 600);

        $releases = Cache::remember('github_releases', $cacheTtl, function () use ($owner, $repo) {
            $token = config('services.github.releases.token');
            $max = config('services.github.releases.max', 50);

            $request = Http::withHeaders([
                'Accept' => 'application/vnd.github+json',
                'X-GitHub-Api-Version' => '2022-11-28',
            ]);

            if ($token) {
                $request = $request->withToken($token);
            }

            $response = $request->get("https://api.github.com/repos/{$owner}/{$repo}/releases", [
                'per_page' => $max,
            ]);

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
                    'body' => $includeBody ? Str::markdown($release['body'] ?? '') : '',
                    'html_url' => $release['html_url'],
                    'published_at' => $release['published_at'],
                    'prerelease' => $release['prerelease'] ?? false,
                ])
                ->values()
                ->toArray();
        });

        return Inertia::render('Changelog/Index', [
            'releases' => $releases,
        ]);
    }
}
