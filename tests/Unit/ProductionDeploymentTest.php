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
