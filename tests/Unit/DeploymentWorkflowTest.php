<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

it('deploys staging automatically and supports manual dispatch', function () {
    $workflow = Yaml::parseFile(__DIR__.'/../../.github/workflows/deploy-staging.yml');

    if (! is_array($workflow)) {
        throw new RuntimeException('Expected the staging deployment workflow to be valid YAML.');
    }

    $triggers = $workflow['on'] ?? null;

    if (! is_array($triggers)) {
        throw new RuntimeException('Expected the staging deployment workflow to define triggers.');
    }

    $pushTrigger = $triggers['push'] ?? null;

    if (! is_array($pushTrigger)) {
        throw new RuntimeException('Expected the staging deployment workflow to define a push trigger.');
    }

    $concurrency = $workflow['concurrency'] ?? null;

    if (! is_array($concurrency)) {
        throw new RuntimeException('Expected the staging deployment workflow to define concurrency controls.');
    }

    expect($pushTrigger['branches'] ?? null)->toBe(['staging'])
        ->and(array_key_exists('workflow_dispatch', $triggers))->toBeTrue()
        ->and($concurrency['cancel-in-progress'] ?? null)->toBeFalse();
});
