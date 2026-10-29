<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

it('deploys only configured branches and leaves retired SSH workflows inactive', function (): void {
    $workflow = Yaml::parseFile(__DIR__.'/../../.github/workflows/cloudflare.yml');
    if (! is_array($workflow) || ! is_array($workflow['on'] ?? null)) {
        throw new RuntimeException('Cloudflare workflow triggers are missing.');
    }
    expect($workflow['on'])->toMatchArray(['push' => ['branches' => ['main', 'cloudflare-staging']], 'pull_request' => ['branches' => ['main', 'cloudflare-staging']]])->toHaveKey('workflow_dispatch');
    foreach (glob(__DIR__.'/../../.github/workflows/*.yml') ?: [] as $active) {
        expect(file_get_contents($active))->not->toContain('appleboy/ssh-action');
    }
    expect(is_file(__DIR__.'/../../.github/workflows/deploy.yml'))->toBeFalse()
        ->and(is_file(__DIR__.'/../../.github/workflows/deploy-staging.yml'))->toBeFalse();
});

it('requires application tests, quality, runtime safety and an inspected image before deployment', function (): void {
    $workflow = Yaml::parseFile(__DIR__.'/../../.github/workflows/cloudflare.yml');
    if (! is_array($workflow) || ! is_array($workflow['jobs'] ?? null)) {
        throw new RuntimeException('Cloudflare workflow jobs are missing.');
    }
    $jobs = $workflow['jobs'];
    $deploy = $jobs['deploy'] ?? null;
    if (! is_array($deploy)) {
        throw new RuntimeException('Cloudflare deployment job is missing.');
    }
    expect($deploy['needs'] ?? null)->toBe(['runtime', 'application-tests', 'application-quality', 'build'])
        ->and($deploy['if'] ?? null)->toContain("github.event_name != 'pull_request'", "needs.build.result == 'success'", "needs.application-tests.result == 'success'", "needs.application-quality.result == 'success'");
    expect($jobs['application-tests'] ?? null)->toMatchArray(['uses' => './.github/workflows/tests.yml'])
        ->and($jobs['application-quality'] ?? null)->toMatchArray(['uses' => './.github/workflows/lint.yml']);
    foreach (['runtime', 'build'] as $job) {
        expect(json_encode($jobs[$job] ?? null, JSON_THROW_ON_ERROR))->not->toContain('secrets.');
    }
    expect(json_encode($deploy, JSON_THROW_ON_ERROR))->toContain('CLOUDFLARE_API_TOKEN', 'STAGING_BASIC_AUTHORIZATION', 'Require the exact tested branch tip');
});

it('serializes releases and keeps the workflow token read only', function (): void {
    $workflow = Yaml::parseFile(__DIR__.'/../../.github/workflows/cloudflare.yml');
    if (! is_array($workflow)) {
        throw new RuntimeException('Cloudflare workflow is missing.');
    }
    expect($workflow['permissions'] ?? null)->toBe(['contents' => 'read'])
        ->and($workflow['concurrency'] ?? null)->toMatchArray(['cancel-in-progress' => false]);
});
