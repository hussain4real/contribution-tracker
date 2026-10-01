<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

/**
 * @return array{exit: int, commands: list<list<string>>, maintenance: bool, queue_running: bool, ssr_running: bool, merged: bool}
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

    if (in_array($scenario, ['stale', 'ancestry'], true)) {
        expect($result['maintenance'])->toBeFalse();
    } else {
        expect($result['maintenance'])->toBeTrue();
        if ($scenario !== 'queue-stop') {
            expect($result['queue_running'])->toBeFalse();
        }
    }

    if (in_array($scenario, ['stale', 'ancestry', 'queue-stop', 'no-master', 'no-workers', 'drain', 'inactive'], true)) {
        expect($result['merged'])->toBeFalse();
        expect(array_filter($commands, fn (array $command): bool => $command[0] === 'composer'))->toBeEmpty();
    }
})->with(['stale', 'ancestry', 'queue-stop', 'no-master', 'no-workers', 'drain', 'inactive', 'composer', 'migration', 'resume', 'empty-resume', 'health']);

it('resumes production only after exact checkout, migration, and consumer verification', function (): void {
    $result = runProductionDeploymentFaultCase('success');

    expect($result['exit'])->toBe(0)
        ->and($result['maintenance'])->toBeFalse()
        ->and($result['queue_running'])->toBeTrue()
        ->and($result['ssr_running'])->toBeTrue()
        ->and($result['merged'])->toBeTrue();
    $commands = $result['commands'];
    $position = function (array $command) use ($commands): int {
        $index = array_search($command, $commands, true);
        if ($index === false) {
            throw new RuntimeException('Expected deployment command was not executed.');
        }

        return $index;
    };
    expect($position(['php', 'artisan', 'down', '--render=errors::503', '--retry=60']))
        ->toBeLessThan($position(['supervisorctl', 'stop', 'queue-worker:*']))
        ->and($position(['systemctl', 'reload', 'php8.4-fpm']))
        ->toBeLessThan($position(['git', 'merge', '--ff-only', str_repeat('1', 40)]))
        ->and($position(['php', 'artisan', 'migrate', '--force']))
        ->toBeLessThan($position(['supervisorctl', 'status', 'ssr']))
        ->and($position(['supervisorctl', 'status', 'ssr']))
        ->toBeLessThan($position(['php', 'artisan', 'up']));
});
