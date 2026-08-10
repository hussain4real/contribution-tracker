<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

it('uses Node 24-compatible GitHub actions in active workflows', function () {
    $expectedActions = [
        'deploy-staging.yml' => [
            'actions/checkout@v7',
            'actions/setup-node@v7',
        ],
        'deploy.yml' => [
            'actions/checkout@v7',
            'actions/setup-node@v7',
        ],
        'lint.yml' => [
            'actions/checkout@v7',
        ],
        'tests.yml' => [
            'actions/checkout@v7',
            'actions/setup-node@v7',
            'actions/upload-artifact@v4',
        ],
        'tia-baseline.yml' => [
            'actions/checkout@v7',
            'actions/setup-node@v7',
            'actions/upload-artifact@v4',
        ],
        'copilot-setup-steps.yml' => [
            'actions/checkout@3d3c42e5aac5ba805825da76410c181273ba90b1 # v7.0.1',
            'actions/setup-node@820762786026740c76f36085b0efc47a31fe5020 # v7.0.0',
            'node-version: "22"',
        ],
    ];

    foreach ($expectedActions as $workflowName => $expectedWorkflowActions) {
        $workflow = file_get_contents(__DIR__.'/../../.github/workflows/'.$workflowName);

        if ($workflow === false) {
            throw new RuntimeException("Unable to read the {$workflowName} workflow.");
        }

        expect($workflow)
            ->not->toContain('actions/checkout@v4')
            ->not->toContain('actions/setup-node@v4')
            ->not->toContain('actions/setup-node@49933ea5288caeca8642d1e84afbd3f7d6820020');

        foreach ($expectedWorkflowActions as $expectedWorkflowAction) {
            expect($workflow)->toContain($expectedWorkflowAction);
        }
    }
});

it('keeps pull request CI on full coverage and publishes its report', function () {
    $workflow = file_get_contents(__DIR__.'/../../.github/workflows/tests.yml');

    if ($workflow === false) {
        throw new RuntimeException('Unable to read the tests workflow.');
    }

    expect(Yaml::parse($workflow))->toBeArray()
        ->and($workflow)->toContain('composer test:coverage -- --ci --coverage-cobertura=coverage/cobertura.xml')
        ->and($workflow)->toContain('name: coverage-cobertura')
        ->and($workflow)->toContain('path: coverage/cobertura.xml')
        ->and($workflow)->not->toContain('test:coverage:tia');
});

it('records and publishes a fresh shared TIA baseline from main', function () {
    $workflow = file_get_contents(__DIR__.'/../../.github/workflows/tia-baseline.yml');

    if ($workflow === false) {
        throw new RuntimeException('Unable to read the TIA baseline workflow.');
    }

    $configuration = Yaml::parse($workflow);

    if (! is_array($configuration)) {
        throw new RuntimeException('Expected the TIA baseline workflow to be valid YAML.');
    }

    $triggers = $configuration['on'] ?? null;

    if (! is_array($triggers)) {
        throw new RuntimeException('Expected the TIA baseline workflow to define triggers.');
    }

    $pushTrigger = $triggers['push'] ?? null;

    if (! is_array($pushTrigger)) {
        throw new RuntimeException('Expected the TIA baseline workflow to define a push trigger.');
    }

    expect($pushTrigger['branches'] ?? null)->toBe(['main'])
        ->and(array_key_exists('schedule', $triggers))->toBeTrue()
        ->and(array_key_exists('workflow_dispatch', $triggers))->toBeTrue()
        ->and($workflow)->toContain('composer test:coverage:tia:fresh')
        ->and($workflow)->toContain('echo "path=$(./vendor/bin/pest --baseline)" >> "$GITHUB_OUTPUT"')
        ->and($workflow)->toContain('name: pest-tia-baseline')
        ->and($workflow)->toContain('include-hidden-files: true')
        ->and($workflow)->toContain('retention-days: 30');
});

it('keeps deployment tests on the canonical full coverage script', function () {
    foreach (['deploy.yml', 'deploy-staging.yml'] as $workflowName) {
        $workflow = file_get_contents(__DIR__.'/../../.github/workflows/'.$workflowName);

        if ($workflow === false) {
            throw new RuntimeException("Unable to read the {$workflowName} workflow.");
        }

        expect($workflow)->toContain('composer test:coverage -- --ci')
            ->not->toContain('test:coverage:tia');
    }
});

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
        ->toContain('if [ "$(git rev-parse origin/staging)" != \'${{ github.sha }}\' ]; then')
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
        ->toContain('REMOTE_STAGING_COMMIT="$(git rev-parse "origin/$STAGING_BRANCH")"')
        ->toContain('if [ "$REMOTE_STAGING_COMMIT" != "$STAGING_COMMIT" ]; then')
        ->toContain('git cat-file -e "${STAGING_COMMIT}^{commit}"')
        ->toContain('git checkout -B "$STAGING_BRANCH" "$STAGING_COMMIT"')
        ->toContain('if [ "$(git rev-parse HEAD)" != "$STAGING_COMMIT" ]; then')
        ->not->toContain('git merge --ff-only')
        ->not->toContain('git merge-base --is-ancestor');

    $fetchPosition = strpos($deploymentScript, 'git fetch origin "$STAGING_BRANCH"');
    $tipCheckPosition = strpos($deploymentScript, 'if [ "$REMOTE_STAGING_COMMIT" != "$STAGING_COMMIT" ]; then');
    $checkoutPosition = strpos($deploymentScript, 'git checkout -B');
    $installPosition = strpos($deploymentScript, 'composer install');

    if (
        $fetchPosition === false
        || $tipCheckPosition === false
        || $checkoutPosition === false
        || $installPosition === false
    ) {
        throw new RuntimeException('Expected the staging deployment script to contain its guarded checkout sequence.');
    }

    expect($fetchPosition)->toBeLessThan($tipCheckPosition)
        ->and($tipCheckPosition)->toBeLessThan($checkoutPosition)
        ->and($checkoutPosition)->toBeLessThan($installPosition);
});
