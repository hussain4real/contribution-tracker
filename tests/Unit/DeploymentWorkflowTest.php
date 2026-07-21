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

    $jobs = $workflow['jobs'] ?? null;

    if (! is_array($jobs)) {
        throw new RuntimeException('Expected the staging deployment workflow to define jobs.');
    }

    $testJob = $jobs['test'] ?? null;

    if (! is_array($testJob)) {
        throw new RuntimeException('Expected the staging deployment workflow to define a test job.');
    }

    $testSteps = $testJob['steps'] ?? null;

    if (! is_array($testSteps)) {
        throw new RuntimeException('Expected the staging deployment workflow to define test steps.');
    }

    $stagingRefValidationStep = $testSteps[0] ?? null;

    if (! is_array($stagingRefValidationStep)) {
        throw new RuntimeException('Expected the staging deployment workflow to validate its branch ref.');
    }

    $deployJob = $jobs['deploy'] ?? null;

    if (! is_array($deployJob)) {
        throw new RuntimeException('Expected the staging deployment workflow to define a deploy job.');
    }

    $deploySteps = $deployJob['steps'] ?? null;

    if (! is_array($deploySteps)) {
        throw new RuntimeException('Expected the staging deployment workflow to define deploy steps.');
    }

    $deployStep = $deploySteps[0] ?? null;

    if (! is_array($deployStep)) {
        throw new RuntimeException('Expected the staging deployment workflow to define its server deploy step.');
    }

    $deployStepConfiguration = $deployStep['with'] ?? null;

    if (! is_array($deployStepConfiguration)) {
        throw new RuntimeException('Expected the staging server deploy step to define configuration.');
    }

    $remoteScript = $deployStepConfiguration['script'] ?? null;

    expect($pushTrigger['branches'] ?? null)->toBe(['staging'])
        ->and(array_key_exists('workflow_dispatch', $triggers))->toBeTrue()
        ->and($concurrency['cancel-in-progress'] ?? null)->toBeFalse()
        ->and($stagingRefValidationStep['run'] ?? null)->toBe('test "$GITHUB_REF" = "refs/heads/staging"')
        ->and($remoteScript)->toBeString()
        ->toStartWith("set -euo pipefail\n")
        ->toContain('git checkout -B staging \'${{ github.sha }}\'')
        ->toContain('${{ github.sha }}')
        ->not->toContain('git checkout -B staging origin/staging');
});

it('checks out the exact staging commit validated by the workflow', function () {
    $deploymentScript = file_get_contents(__DIR__.'/../../deployment/deploy-staging.sh');

    if ($deploymentScript === false) {
        throw new RuntimeException('Unable to read the staging deployment script.');
    }

    expect($deploymentScript)
        ->toContain('STAGING_COMMIT="${STAGING_COMMIT:-}"')
        ->toContain('git merge-base --is-ancestor "$STAGING_COMMIT" "origin/$STAGING_BRANCH"')
        ->toContain('git cat-file -e "${STAGING_COMMIT}^{commit}"')
        ->toContain('git checkout -B "$STAGING_BRANCH" "$STAGING_COMMIT"')
        ->toContain('if [ "$(git rev-parse HEAD)" != "$STAGING_COMMIT" ]; then')
        ->not->toContain('git merge --ff-only');

    $fetchPosition = strpos($deploymentScript, 'git fetch origin "$STAGING_BRANCH"');
    $ancestorCheckPosition = strpos($deploymentScript, 'git merge-base --is-ancestor');
    $checkoutPosition = strpos($deploymentScript, 'git checkout -B');
    $installPosition = strpos($deploymentScript, 'composer install');

    if (
        $fetchPosition === false
        || $ancestorCheckPosition === false
        || $checkoutPosition === false
        || $installPosition === false
    ) {
        throw new RuntimeException('Expected the staging deployment script to contain its guarded checkout sequence.');
    }

    expect($fetchPosition)->toBeLessThan($ancestorCheckPosition)
        ->and($ancestorCheckPosition)->toBeLessThan($checkoutPosition)
        ->and($checkoutPosition)->toBeLessThan($installPosition);
});
