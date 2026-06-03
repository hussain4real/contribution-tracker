<?php

declare(strict_types=1);

use App\Services\PasskeyAllowedOrigins;

it('allows herd https origins for local test app urls', function () {
    expect(PasskeyAllowedOrigins::fromAppUrl('http://contribution-tracker.test'))->toBe([
        'http://contribution-tracker.test',
        'https://contribution-tracker.test',
    ]);
});

it('does not add insecure production variants by default', function () {
    expect(PasskeyAllowedOrigins::fromAppUrl('https://familyfunds.app'))->toBe([
        'https://familyfunds.app',
    ]);
});

it('includes explicitly configured passkey origins', function () {
    expect(PasskeyAllowedOrigins::fromAppUrl(
        'https://familyfunds.app',
        'https://www.familyfunds.app/, https://admin.familyfunds.app/settings',
    ))->toBe([
        'https://familyfunds.app',
        'https://www.familyfunds.app',
        'https://admin.familyfunds.app',
    ]);
});

it('deduplicates local origins that are configured explicitly', function () {
    expect(PasskeyAllowedOrigins::fromAppUrl(
        'http://contribution-tracker.test/',
        ['https://contribution-tracker.test'],
    ))->toBe([
        'http://contribution-tracker.test',
        'https://contribution-tracker.test',
    ]);
});

it('ignores unusable app urls', function () {
    expect(PasskeyAllowedOrigins::fromAppUrl('http://'))->toBe([]);
});

it('ignores configured origins that cannot become browser origins', function () {
    expect(PasskeyAllowedOrigins::fromAppUrl('https://familyfunds.app', 123))->toBe([
        'https://familyfunds.app',
    ]);

    expect(PasskeyAllowedOrigins::fromAppUrl('https://familyfunds.app', [
        [],
        ' ',
        'mailto:hello@example.com',
        'relative/path',
    ]))->toBe([
        'https://familyfunds.app',
    ]);
});

it('formats local ipv6 origins with brackets', function () {
    expect(PasskeyAllowedOrigins::fromAppUrl('http://[::1]:8000'))->toBe([
        'http://[::1]:8000',
        'https://[::1]:8000',
    ]);
});
