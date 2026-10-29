<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;

test('the native capacity dashboard renders under the owner target during background load', function (): void {
    if (getenv('D1_CAPACITY_BROWSER') !== '1') {
        $this->markTestSkipped('Opt-in browser proof restricted to the synthetic capacity hostname.');
    }
    $private = storage_path('app/private/d1-staging');
    $decoded = json_decode(File::get($private.'/staging-secrets.json'), true, flags: JSON_THROW_ON_ERROR);
    if (! is_array($decoded)) {
        throw new RuntimeException('Invalid staging browser credentials.');
    }
    $secrets = [];
    $candidate = getenv('D1_CAPACITY_CANDIDATE') === 'lean';
    foreach (['basic_user', 'basic_password', 'secretary_password', 'container_admin_token'] as $key) {
        $value = $decoded[$key] ?? null;
        if (! is_string($value) || $value === '') {
            throw new RuntimeException('Missing staging browser credential.');
        }
        $secrets[$key] = $value;
    }
    $base = 'https://familyfunds-d1-capacity.familyfunds-lab.workers.dev';
    $samples = [];
    $maximumReadyMs = 0.0;
    $modalVerified = false;
    $modalSeconds = null;
    $passed = false;
    pest()->browser()->timeout(45_000);
    try {
        $page = visit($base.'/login', ['httpCredentials' => [
            'username' => $secrets['basic_user'], 'password' => $secrets['basic_password'],
        ], 'extraHTTPHeaders' => $candidate ? [
            ['name' => 'X-D1-Staging-Candidate', 'value' => 'lean'],
            ['name' => 'X-Staging-Admin', 'value' => $secrets['container_admin_token']],
        ] : []]);
        $page->fill('email', 'd1-secretary@example.invalid')
            ->fill('password', $secrets['secretary_password'])
            ->click('@login-button')
            ->assertSee('Total Collected');
        file_put_contents($private.'/capacity-browser-ready.json', json_encode(['ready' => true, 'at' => microtime(true)]));
        chmod($private.'/capacity-browser-ready.json', 0600);
        $deadline = microtime(true) + 45;
        while (! is_file($private.'/capacity-browser-load-started.json') && microtime(true) < $deadline) {
            usleep(50_000);
        }
        expect(is_file($private.'/capacity-browser-load-started.json'))->toBeTrue('The background load must acknowledge browser readiness.');
        foreach (range(1, 5) as $round) {
            $page->refresh()->assertSee('Total Collected')->assertNoJavaScriptErrors();
            $raw = $page->script('(() => { const assets = performance.getEntriesByType("resource").filter(entry => new URL(entry.name).pathname.startsWith("/build/assets/")); return {ready_ms: performance.now(), navigation_start_unix_ms: performance.timeOrigin, dom_content_loaded_ms: performance.getEntriesByType("navigation")[0].domContentLoadedEventEnd, asset_requests: assets.length, asset_transfer_bytes: assets.reduce((sum, entry) => sum + entry.transferSize, 0)}; })()');
            if (! is_array($raw)) {
                throw new RuntimeException('Browser timing data is not an object.');
            }
            $timing = [];
            foreach (['ready_ms', 'navigation_start_unix_ms', 'dom_content_loaded_ms', 'asset_requests', 'asset_transfer_bytes'] as $key) {
                $value = $raw[$key] ?? null;
                if (! is_int($value) && ! is_float($value)) {
                    throw new RuntimeException('Browser timing data contains a non-numeric value.');
                }
                $timing[$key] = $value;
                if ($key === 'ready_ms') {
                    $maximumReadyMs = max($maximumReadyMs, $value);
                }
            }
            $samples[] = ['round' => $round, ...$timing];
        }
        $modalStarted = microtime(true);
        $page->click('Overdue Members')->assertSee('requiring attention.')->assertNoJavaScriptErrors();
        $modalSeconds = microtime(true) - $modalStarted;
        $modalVerified = true;
        expect($maximumReadyMs)->toBeLessThan(3000);
        $passed = true;
    } finally {
        pest()->browser()->timeout(15_000);
        $result = $candidate ? 'capacity-browser-lean-result.json' : 'capacity-browser-result.json';
        file_put_contents(base_path('deployment/d1-proof/'.$result), json_encode([
            'status' => $passed ? 'passed' : 'failed',
            'candidate' => $candidate,
            'target' => $base, 'actor' => 'synthetic financial secretary',
            'measurement' => 'Warm full browser reload through visible dashboard assertion, including transferred assets; 19 distinct member clients measured separately',
            'samples' => $samples, 'large_optional_modal_verified' => $modalVerified,
            'large_optional_modal_seconds' => $modalSeconds,
        ], JSON_PRETTY_PRINT)."\n");
    }
});
