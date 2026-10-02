<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

/**
 * @return array{exit: int, commands: list<list<string>>, maintenance: bool, queue_running: bool, ssr_running: bool, nightwatch_running: bool, merged: bool}
 */
function runProductionDeploymentFaultCase(string $scenario): array
{
    $script = Yaml::parseFile(__DIR__.'/../../.github/workflows/deploy.yml');
    foreach (['jobs', 'deploy', 'steps', 0, 'with', 'script'] as $key) {
        if (! is_array($script) || ! array_key_exists($key, $script)) {
            throw new RuntimeException('Missing production deployment script.');
        }
        $script = $script[$key];
    }
    if (! is_string($script)) {
        throw new RuntimeException('Expected a production shell script.');
    }
    $process = new Process(['python3', __DIR__.'/../Support/contribution_deploy_harness.py', $scenario]);
    $process->setInput($script);
    $process->mustRun();
    $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    if (! is_array($result) || ! is_int($result['exit'] ?? null) || ! is_array($result['commands'] ?? null)) {
        throw new RuntimeException('Invalid deployment harness result.');
    }
    $commands = [];
    foreach ($result['commands'] as $command) {
        if (! is_array($command)) {
            throw new RuntimeException('Invalid deployment command.');
        }
        $arguments = [];
        foreach ($command as $argument) {
            if (! is_string($argument)) {
                throw new RuntimeException('Invalid deployment argument.');
            }
            $arguments[] = $argument;
        }
        $commands[] = $arguments;
    }
    $boolean = function (string $key) use ($result): bool {
        $value = $result[$key] ?? null;
        if (! is_bool($value)) {
            throw new RuntimeException('Invalid deployment state.');
        }

        return $value;
    };

    return [
        'exit' => $result['exit'],
        'commands' => $commands,
        'maintenance' => $boolean('maintenance'),
        'queue_running' => $boolean('queue_running'),
        'ssr_running' => $boolean('ssr_running'),
        'nightwatch_running' => $boolean('nightwatch_running'),
        'merged' => $boolean('merged'),
    ];
}

it('fails closed when production admission, draining, or release checks fail', function (string $scenario): void {
    $result = runProductionDeploymentFaultCase($scenario);
    $commands = $result['commands'];

    expect($result['exit'])->not->toBe(0);

    foreach ($commands as $command) {
        expect(implode(' ', $command))->not->toContain('staging');
        expect($command)->not->toBe(['systemctl', 'stop', 'php8.4-fpm']);
    }

    if (in_array($scenario, ['stale', 'ancestry', 'supervisor-control-denied', 'preflight-denied', 'preflight-stopped', 'preflight-empty'], true)) {
        expect($result['maintenance'])->toBeFalse();
        expect($result['queue_running'])->toBeTrue()
            ->and($result['ssr_running'])->toBeTrue()
            ->and($result['nightwatch_running'])->toBeTrue();
        expect(array_filter($commands, fn (array $command): bool => $command === ['php', 'artisan', 'down', '--render=errors::503', '--retry=60']
            || ($command[0] === 'supervisorctl' && $command[1] === 'stop')))->toBeEmpty();
    } else {
        expect($result['maintenance'])->toBeTrue();
        if ($scenario !== 'queue-stop') {
            expect($result['queue_running'])->toBeFalse();
        }
        if ($scenario !== 'nightwatch-stop') {
            expect($result['nightwatch_running'])->toBeFalse();
        }
    }

    if (in_array($scenario, ['stale', 'ancestry', 'supervisor-control-denied', 'preflight-denied', 'preflight-stopped', 'preflight-empty', 'partial-down', 'queue-stop', 'nightwatch-stop', 'no-master', 'no-workers', 'drain', 'reload-failure', 'inactive'], true)) {
        expect($result['merged'])->toBeFalse();
        expect(array_filter($commands, fn (array $command): bool => $command[0] === 'composer'))->toBeEmpty();
    }
})->with(['stale', 'ancestry', 'supervisor-control-denied', 'preflight-denied', 'preflight-stopped', 'preflight-empty', 'partial-down', 'queue-stop', 'nightwatch-stop', 'no-master', 'no-workers', 'drain', 'reload-failure', 'inactive', 'composer', 'migration', 'resume', 'empty-resume', 'nightwatch-restart', 'nightwatch-status', 'nightwatch-empty-status', 'final-drain', 'final-reload-failure', 'health']);

it('preserves healthy consumers when maintenance entry cannot be confirmed', function (string $scenario, bool $maintenance, int $exit): void {
    $result = runProductionDeploymentFaultCase($scenario);

    expect($result['exit'])->toBe($exit)
        ->and($result['maintenance'])->toBe($maintenance)
        ->and($result['queue_running'])->toBeTrue()
        ->and($result['ssr_running'])->toBeTrue()
        ->and($result['nightwatch_running'])->toBeTrue()
        ->and($result['merged'])->toBeFalse();
    expect(array_filter($result['commands'], fn (array $command): bool => in_array($command[0], ['composer', 'npm', 'systemctl'], true)
        || ($command[0] === 'supervisorctl' && in_array($command[1], ['stop', 'restart'], true))
        || ($command[0] === 'php' && $command[1] === 'artisan' && in_array($command[2], ['migrate', 'up'], true))))->toBeEmpty();
})->with([
    'failure before activation' => ['down-before-state', false, 1],
    'failure with unknown state' => ['down-before-state-probe-error', false, 1],
    'success without activation' => ['down-success-without-state', false, 1],
    'bootstrap or probe error' => ['probe-error', true, 1],
    'probe timeout' => ['probe-timeout', true, 1],
    'early successful probe exit' => ['probe-early-exit', true, 1],
    'unexpected probe output' => ['probe-unexpected-output', true, 1],
    'TERM before activation' => ['term-before-state', false, 143],
    'INT before activation' => ['int-before-state', false, 130],
]);

it('stops consumers after maintenance is confirmed while preserving the original failure', function (string $scenario, int $exit, bool $merged): void {
    $result = runProductionDeploymentFaultCase($scenario);

    expect($result['exit'])->toBe($exit)
        ->and($result['maintenance'])->toBeTrue()
        ->and($result['queue_running'])->toBeFalse()
        ->and($result['ssr_running'])->toBeFalse()
        ->and($result['nightwatch_running'])->toBeFalse()
        ->and($result['merged'])->toBe($merged);
    expect(array_filter($result['commands'], fn (array $command): bool => $command === ['php', 'artisan', 'up']))->toBeEmpty();
    if (! $merged) {
        expect(array_filter($result['commands'], fn (array $command): bool => in_array($command[0], ['composer', 'npm'], true)))->toBeEmpty();
    }
})->with([
    'cleanup entry succeeds after initial failure' => ['down-retry-recovers', 1, false],
    'state written before command failure' => ['partial-down', 1, false],
    'TERM after activation' => ['term-after-state', 143, false],
    'INT after activation' => ['int-after-state', 130, false],
    'TERM after checkout changes' => ['term-postmerge', 143, true],
    'probe error after consumers resume' => ['postmerge-probe-error', 2, true],
    'bootstrap failure after checkout changes' => ['postmerge-broken-bootstrap', 1, true],
]);

it('leaves preexisting stopped consumers unchanged during admission failure', function (string $consumer): void {
    $result = runProductionDeploymentFaultCase('initially-stopped-'.$consumer);

    expect($result['exit'])->not->toBe(0)
        ->and($result['maintenance'])->toBeFalse()
        ->and($result['queue_running'])->toBe($consumer !== 'queue')
        ->and($result['ssr_running'])->toBe($consumer !== 'ssr')
        ->and($result['nightwatch_running'])->toBe($consumer !== 'nightwatch')
        ->and($result['merged'])->toBeFalse();
    expect(array_filter($result['commands'], fn (array $command): bool => in_array($command[0], ['composer', 'npm', 'systemctl'], true)
        || ($command[0] === 'supervisorctl' && in_array($command[1], ['stop', 'restart'], true))
        || ($command[0] === 'php' && $command[1] === 'artisan')))->toBeEmpty();
})->with(['queue', 'ssr', 'nightwatch']);

it('requires fresh maintenance confirmation when reopening traffic fails', function (string $scenario, bool $maintenance, bool $running, int $exit): void {
    $result = runProductionDeploymentFaultCase($scenario);

    expect($result['exit'])->toBe($exit)
        ->and($result['maintenance'])->toBe($maintenance)
        ->and($result['queue_running'])->toBe($running)
        ->and($result['ssr_running'])->toBe($running)
        ->and($result['nightwatch_running'])->toBe($running)
        ->and($result['merged'])->toBeTrue();
    $up = array_search(['php', 'artisan', 'up'], $result['commands'], true);
    if ($up === false) {
        throw new RuntimeException('Expected maintenance removal attempt.');
    }
    $cleanup = array_slice($result['commands'], $up + 1);
    $stops = array_values(array_filter($cleanup, fn (array $command): bool => $command[0] === 'supervisorctl' && $command[1] === 'stop'));
    expect($stops)->toBe($running ? [] : [
        ['supervisorctl', 'stop', 'queue-worker:*'],
        ['supervisorctl', 'stop', 'ssr'],
        ['supervisorctl', 'stop', 'nightwatch'],
    ]);
    expect(array_filter($cleanup, fn (array $command): bool => in_array($command[0], ['git', 'composer', 'npm'], true)
        || ($command[0] === 'php' && $command[1] === 'artisan' && in_array($command[2], ['migrate', 'up'], true))
        || ($command[0] === 'supervisorctl' && $command[1] === 'restart')))->toBeEmpty();
})->with([
    'health failure with failed reentry before state' => ['health-reentry-before-state', false, true, 17],
    'health failure with partial reentry' => ['health-reentry-after-state', true, false, 17],
    'health failure with successful reentry without state' => ['health-reentry-no-state', false, true, 17],
    'health failure with unknown reentry state' => ['health-reentry-probe-error', true, true, 17],
    'health failure with timed out reentry probe' => ['health-reentry-probe-timeout', true, true, 17],
    'health failure with early successful probe exit' => ['health-reentry-probe-empty', true, true, 17],
    'health failure with unexpected probe output' => ['health-reentry-probe-noise', true, true, 17],
    'health failure with failed reentry and probe' => ['health-reentry-down-and-probe-error', false, true, 17],
    'up failure before removal' => ['up-before-state-failure', true, false, 17],
    'up failure after removal' => ['up-after-state-failure', false, true, 17],
    'TERM before removal' => ['term-up-before-state', true, false, 143],
    'INT before removal' => ['int-up-before-state', true, false, 130],
    'TERM after removal' => ['term-up-after-state', false, true, 143],
    'INT after removal' => ['int-up-after-state', false, true, 130],
]);

it('revokes historical confirmation when maintenance is observed inactive', function (string $scenario, bool $maintenance, bool $running, int $exit): void {
    $result = runProductionDeploymentFaultCase($scenario);

    expect($result['exit'])->toBe($exit)
        ->and($result['maintenance'])->toBe($maintenance)
        ->and($result['queue_running'])->toBe($running)
        ->and($result['ssr_running'])->toBe($running)
        ->and($result['nightwatch_running'])->toBe($running)
        ->and($result['merged'])->toBeTrue();
    $restart = array_search(['supervisorctl', 'restart', 'nightwatch'], $result['commands'], true);
    if ($restart === false) {
        throw new RuntimeException('Expected resumed production consumers.');
    }
    $cleanup = array_slice($result['commands'], $restart + 1);
    expect(array_filter($cleanup, fn (array $command): bool => $command === ['php', 'artisan', 'up']
        || in_array($command[0], ['git', 'composer', 'npm'], true)))->toBeEmpty();
    $stops = array_values(array_filter($cleanup, fn (array $command): bool => $command[0] === 'supervisorctl' && $command[1] === 'stop'));
    expect($stops)->toBe($running ? [] : [
        ['supervisorctl', 'stop', 'queue-worker:*'],
        ['supervisorctl', 'stop', 'ssr'],
        ['supervisorctl', 'stop', 'nightwatch'],
    ]);
})->with([
    'explicit inactive final probe' => ['final-inactive-reentry-before-state', false, true, 1],
    'explicit inactive proof followed by unknown cleanup probe' => ['final-inactive-reentry-probe-error', false, true, 1],
    'unknown final probe retains unrevoked confirmation' => ['final-unknown-reentry-before-state', true, false, 2],
    'inactive observed during failure cleanup' => ['cleanup-inactive-after-resume', false, true, 17],
]);

it('requires separate stop authorization even when consumer status access is allowed', function (): void {
    $result = runProductionDeploymentFaultCase('supervisor-stop-denied');

    expect($result['exit'])->not->toBe(0)
        ->and($result['maintenance'])->toBeTrue()
        ->and($result['queue_running'])->toBeTrue()
        ->and($result['ssr_running'])->toBeTrue()
        ->and($result['nightwatch_running'])->toBeTrue()
        ->and($result['merged'])->toBeFalse();
    expect(array_filter($result['commands'], fn (array $command): bool => in_array($command[0], ['composer', 'npm'], true)
        || ($command[0] === 'php' && $command[1] === 'artisan' && $command[2] === 'migrate')))->toBeEmpty();
});

it('resumes production only after exact checkout, migration, and consumer verification', function (): void {
    $result = runProductionDeploymentFaultCase('success');

    expect($result['exit'])->toBe(0)
        ->and($result['maintenance'])->toBeFalse()
        ->and($result['queue_running'])->toBeTrue()
        ->and($result['ssr_running'])->toBeTrue()
        ->and($result['nightwatch_running'])->toBeTrue()
        ->and($result['merged'])->toBeTrue();
    $commands = $result['commands'];
    $position = function (array $command, bool $last = false) use ($commands): int {
        $index = array_search($command, $last ? array_reverse($commands, true) : $commands, true);
        if ($index === false) {
            throw new RuntimeException('Expected deployment command was not executed.');
        }

        return $index;
    };
    $probes = array_keys(array_filter($commands, fn (array $command): bool => $command[0] === 'php' && $command[1] === '-r' && str_contains($command[2], 'isDownForMaintenance')));
    expect($probes)->toHaveCount(3);
    expect($probes[0])->toBeGreaterThan($position(['php', 'artisan', 'down', '--render=errors::503', '--retry=60']))
        ->toBeLessThan($position(['supervisorctl', 'stop', 'queue-worker:*']));
    expect($position(['supervisorctl', 'status', 'nightwatch']))
        ->toBeLessThan($position(['php', 'artisan', 'down', '--render=errors::503', '--retry=60']))
        ->and($position(['php', 'artisan', 'down', '--render=errors::503', '--retry=60']))
        ->toBeLessThan($position(['supervisorctl', 'stop', 'queue-worker:*']))
        ->and($position(['supervisorctl', 'stop', 'nightwatch']))
        ->toBeLessThan($position(['git', 'merge', '--ff-only', str_repeat('1', 40)]))
        ->and($position(['systemctl', 'reload', 'php8.4-fpm']))
        ->toBeLessThan($position(['git', 'merge', '--ff-only', str_repeat('1', 40)]))
        ->and($position(['php', 'artisan', 'migrate', '--force']))
        ->toBeLessThan($position(['supervisorctl', 'status', 'ssr'], true))
        ->and($position(['supervisorctl', 'status', 'ssr'], true))
        ->toBeLessThan($position(['supervisorctl', 'status', 'nightwatch'], true))
        ->and($position(['supervisorctl', 'status', 'nightwatch'], true))
        ->toBeLessThan($position(['php', 'artisan', 'up']));
});

it('tolerates a confirmed worker exit at either drain barrier', function (string $scenario): void {
    $result = runProductionDeploymentFaultCase($scenario);

    expect($result['exit'])->toBe(0)
        ->and($result['merged'])->toBeTrue()
        ->and($result['maintenance'])->toBeFalse()
        ->and($result['queue_running'])->toBeTrue()
        ->and($result['ssr_running'])->toBeTrue()
        ->and($result['nightwatch_running'])->toBeTrue();
})->with(['fpm-census-exit', 'fpm-poll-exit', 'fpm-final-census-exit', 'fpm-final-poll-exit', 'fpm-retired-pid']);

it('refuses uncertain or malformed worker identity before changing the release', function (string $fault): void {
    $result = runProductionDeploymentFaultCase('fpm-'.$fault);

    expect($result['exit'])->not->toBe(0)
        ->and($result['merged'])->toBeFalse()
        ->and($result['maintenance'])->toBeTrue()
        ->and($result['queue_running'])->toBeFalse()
        ->and($result['ssr_running'])->toBeFalse()
        ->and($result['nightwatch_running'])->toBeFalse();
    expect(array_filter($result['commands'], fn (array $command): bool => in_array($command[0], ['composer', 'npm'], true)
        || $command === ['php', 'artisan', 'up']))->toBeEmpty();
})->with([
    'census-permission', 'poll-permission', 'census-live', 'poll-live', 'census-kernel-error', 'poll-kernel-error',
    'census-missing', 'poll-missing', 'census-empty', 'poll-empty', 'census-truncated', 'poll-truncated',
    'census-short', 'poll-short', 'census-nonnumeric', 'poll-nonnumeric', 'census-wrong-pid', 'poll-wrong-pid',
    'census-wrong-parent', 'poll-wrong-parent', 'census-bad-state', 'poll-bad-state',
    'census-timeout', 'poll-timeout', 'census-failure', 'poll-failure', 'census-noise', 'poll-noise', 'comm-name',
    'child-zero', 'child-negative', 'child-overflow', 'child-nonnumeric',
]);

it('keeps maintenance when the final drain cannot prove original workers exited', function (string $fault): void {
    $result = runProductionDeploymentFaultCase('fpm-final-'.$fault);

    expect($result['exit'])->not->toBe(0)
        ->and($result['merged'])->toBeTrue()
        ->and($result['maintenance'])->toBeTrue()
        ->and($result['queue_running'])->toBeFalse()
        ->and($result['ssr_running'])->toBeFalse()
        ->and($result['nightwatch_running'])->toBeFalse();
    expect(array_filter($result['commands'], fn (array $command): bool => $command === ['php', 'artisan', 'up']))->toBeEmpty();
})->with([
    'census-permission', 'poll-permission', 'census-live', 'poll-live', 'census-kernel-error', 'poll-kernel-error',
    'census-missing', 'poll-missing', 'census-empty', 'poll-empty', 'census-truncated', 'poll-truncated',
    'census-short', 'poll-short', 'census-nonnumeric', 'poll-nonnumeric', 'census-wrong-pid', 'poll-wrong-pid',
    'census-wrong-parent', 'poll-wrong-parent', 'census-bad-state', 'poll-bad-state',
    'census-timeout', 'poll-timeout', 'census-failure', 'poll-failure', 'census-noise', 'poll-noise', 'comm-name',
]);

it('checks POSIX availability before maintenance or consumer shutdown', function (): void {
    $result = runProductionDeploymentFaultCase('fpm-no-posix');

    expect($result['exit'])->not->toBe(0)
        ->and($result['merged'])->toBeFalse()
        ->and($result['maintenance'])->toBeFalse()
        ->and($result['queue_running'])->toBeTrue()
        ->and($result['ssr_running'])->toBeTrue()
        ->and($result['nightwatch_running'])->toBeTrue();
    expect(array_filter($result['commands'], fn (array $command): bool => $command === ['php', 'artisan', 'down', '--render=errors::503', '--retry=60']
        || ($command[0] === 'supervisorctl' && $command[1] === 'stop')))->toBeEmpty();
});
