<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

it('normalizes writable paths without changing report access or exposing Passport keys', function () {
    $projectRoot = dirname(__DIR__, 2);
    $script = file_get_contents($projectRoot.'/deployment/ensure-storage-permissions.sh');
    $deployScript = file_get_contents($projectRoot.'/deployment/deploy.sh');
    $setupScript = file_get_contents($projectRoot.'/deployment/setup-server.sh');
    $provisionScript = file_get_contents($projectRoot.'/deployment/provision-report-permissions.sh');
    $workflow = file_get_contents($projectRoot.'/.github/workflows/deploy.yml');

    if ($script === false || $deployScript === false || $setupScript === false || $provisionScript === false || $workflow === false) {
        throw new RuntimeException('Unable to read the deployment permission fixtures.');
    }

    $setupScript .= $provisionScript;

    expect($script)
        ->toContain('REPORTS_DIR="$APP_DIR/storage/app/private/reports"')
        ->not->toContain('chown -R')
        ->toContain('find "${WRITABLE_PATHS[@]}" -path "$REPORTS_DIR" -prune -o -exec /usr/bin/chown -h deployer:www-data {} +')
        ->toContain('find "${WRITABLE_PATHS[@]}" -path "$REPORTS_DIR" -prune -o -type d -exec /usr/bin/chmod 2775 {} +')
        ->toContain('find "${WRITABLE_PATHS[@]}" -path "$REPORTS_DIR" -prune -o -path "$APP_DIR/storage/oauth-private.key" -prune -o -path "$APP_DIR/storage/oauth-public.key" -prune -o -type f -exec /usr/bin/chmod 0664 {} +')
        ->toContain('find "$REPORTS_DIR" -type d -exec /usr/bin/chmod g+rx {} +')
        ->toContain('find "$REPORTS_DIR" -type f -exec /usr/bin/chmod g+r {} +')
        ->toContain('if [[ ! -e "$REPORTS_DIR" && ! -L "$REPORTS_DIR" ]]; then');

    foreach (['private', 'public'] as $keyType) {
        $keyCommand = '/usr/bin/chmod 0640 "$APP_DIR/storage/oauth-'.$keyType.'.key"';
        $normalizationCommand = 'find "${WRITABLE_PATHS[@]}" -path "$REPORTS_DIR" -prune -o -path "$APP_DIR/storage/oauth-private.key"';

        expect($script)
            ->toContain($keyCommand)
            ->not->toContain('sudo '.$keyCommand);
        $keyPosition = strpos($script, $keyCommand);
        $normalizationPosition = strpos($script, $normalizationCommand);
        if ($keyPosition === false || $normalizationPosition === false) {
            throw new RuntimeException('Expected key hardening and normalization commands to exist.');
        }
        expect($keyPosition)->toBeLessThan($normalizationPosition);
    }

    expect($setupScript)->toContain('/etc/sudoers.d/familyfunds-storage-permissions');
    foreach (explode("\n", $script) as $line) {
        if (! str_starts_with($line, 'sudo /usr/bin/find ')) {
            continue;
        }

        $sudoersCommand = str_replace(
            ['sudo ', '"${WRITABLE_PATHS[@]}"', '"$REPORTS_DIR"', '"$APP_DIR/storage/oauth-private.key"', '"$APP_DIR/storage/oauth-public.key"', 'deployer:www-data'],
            ['', '/var/www/contribution-tracker/storage /var/www/contribution-tracker/bootstrap/cache', '/var/www/contribution-tracker/storage/app/private/reports', '/var/www/contribution-tracker/storage/oauth-private.key', '/var/www/contribution-tracker/storage/oauth-public.key', 'deployer\\:www-data'],
            $line,
        );
        expect($setupScript)->toContain('deployer ALL=(root) NOPASSWD: '.$sudoersCommand);
    }

    expect($setupScript)
        ->toContain('usermod -aG www-data deployer')
        ->not->toContain('/usr/bin/chown -R deployer\\:www-data /var/www/contribution-tracker/storage')
        ->not->toContain('/usr/bin/chmod 0640 /var/www/contribution-tracker/storage/oauth-private.key')
        ->not->toContain('/usr/bin/chmod 0640 /var/www/contribution-tracker/storage/oauth-public.key')
        ->toContain('/usr/bin/install -d -o deployer -g www-data -m 2775 /var/www/contribution-tracker/storage/app/private/reports');

    expect($setupScript)
        ->toContain('deployer ALL=(root) NOPASSWD: /usr/bin/find /var/www/contribution-tracker/storage/app/private/reports -type d -exec /usr/bin/chmod g+rx {} +')
        ->toContain('deployer ALL=(root) NOPASSWD: /usr/bin/find /var/www/contribution-tracker/storage/app/private/reports -type f -exec /usr/bin/chmod g+r {} +');

    expect($deployScript)->toContain('bash deployment/ensure-storage-permissions.sh "$APP_DIR"');
    expect($workflow)
        ->not->toContain('name: Bootstrap writable-path permissions')
        ->not->toContain('username: root')
        ->not->toContain('/etc/sudoers.d/familyfunds-storage-permissions')
        ->toContain('username: deployer')
        ->toContain('bash deployment/ensure-storage-permissions.sh /var/www/contribution-tracker');
});

it('preserves report metadata while normalizing other files on repeated runs', function (array $keys, bool $existingReports) {
    $fixtureRoot = sys_get_temp_dir().'/familyfund-permissions-'.bin2hex(random_bytes(8));
    $applicationRoot = $fixtureRoot.'/app';
    $binDirectory = $fixtureRoot.'/bin';
    $reportsDirectory = $applicationRoot.'/storage/app/private/reports';
    $commandLog = $fixtureRoot.'/ownership.log';

    mkdir($binDirectory, 0755, true);
    mkdir($applicationRoot.'/storage/logs', 0700, true);
    mkdir($applicationRoot.'/bootstrap/cache', 0700, true);
    mkdir($applicationRoot.'/storage/app/private', 0700, true);
    file_put_contents($applicationRoot.'/storage/logs/example.log', 'ordinary fixture');
    chmod($applicationRoot.'/storage/logs/example.log', 0600);
    file_put_contents($commandLog, '');

    $keyPaths = [];
    foreach ($keys as $keyType) {
        if (! is_string($keyType)) {
            throw new RuntimeException('Expected a string key type in the fixture dataset.');
        }
        $keyPath = $applicationRoot.'/storage/oauth-'.$keyType.'.key';
        file_put_contents($keyPath, 'synthetic fixture, not a key');
        chmod($keyPath, 0664);
        $keyPaths[] = $keyPath;
    }

    $reportPaths = [];
    if ($existingReports) {
        mkdir($reportsDirectory.'/22/nested', 0700, true);
        chmod($reportsDirectory, 0750);
        chmod($reportsDirectory.'/22', 02700);
        chmod($reportsDirectory.'/22/nested', 0700);
        $reportFile = $reportsDirectory.'/22/nested/report.pdf';
        file_put_contents($reportFile, 'synthetic report');
        chmod($reportFile, 0600);
        foreach ([$reportsDirectory, $reportsDirectory.'/22', $reportsDirectory.'/22/nested', $reportFile] as $path) {
            $metadata = stat($path);
            if ($metadata === false) {
                throw new RuntimeException('Unable to read report fixture metadata.');
            }
            $reportPaths[$path] = array_intersect_key($metadata, array_flip(['ino', 'uid', 'gid', 'mtime']));
        }
    }

    // Execute real find/chmod; replace privileged ownership and install with spies.
    $chmodBinary = is_file('/usr/bin/chmod') ? '/usr/bin/chmod' : '/bin/chmod';
    $script = file_get_contents(dirname(__DIR__, 2).'/deployment/ensure-storage-permissions.sh');
    if ($script === false) {
        throw new RuntimeException('Unable to read the deployment permission fixture.');
    }
    $script = str_replace(
        ['/usr/bin/chown', '/usr/bin/install', '/usr/bin/chmod'],
        [$binDirectory.'/chown', $binDirectory.'/install', $chmodBinary],
        $script,
    );
    file_put_contents($fixtureRoot.'/permissions.sh', $script);
    file_put_contents($binDirectory.'/sudo', "#!/bin/bash\nset -euo pipefail\nexec \"\$@\"\n");
    file_put_contents($binDirectory.'/chown', "#!/bin/bash\nset -euo pipefail\nprintf '%s\\n' \"\$@\" >> \"\$PERMISSION_COMMAND_LOG\"\n");
    file_put_contents($binDirectory.'/install', '#!/bin/bash'."\nset -euo pipefail\n".'target="${@: -1}"'."\n".'mkdir -p "$target"'."\n".$chmodBinary.' 2775 "$target"'."\n");
    foreach (['sudo', 'chown', 'install'] as $executable) {
        chmod($binDirectory.'/'.$executable, 0755);
    }

    try {
        for ($run = 0; $run < 2; $run++) {
            $process = new Process(['bash', $fixtureRoot.'/permissions.sh', $applicationRoot], null, [
                'PATH' => $binDirectory.':'.getenv('PATH'),
                'PERMISSION_COMMAND_LOG' => $commandLog,
            ]);
            $process->mustRun();
            clearstatcache();

            foreach ($keyPaths as $keyPath) {
                expect(fileperms($keyPath) & 0777)->toBe(0640);
            }
            expect(fileperms($applicationRoot.'/storage/logs/example.log') & 0777)->toBe(0664);
            expect(fileperms($applicationRoot.'/storage/logs') & 0777)->toBe(0775);
            expect(file_get_contents($commandLog))->not->toContain($reportsDirectory);
            expect(file_get_contents($commandLog))->toContain($applicationRoot.'/storage/logs/example.log');

            foreach ($reportPaths as $path => $before) {
                $after = stat($path);
                if ($after === false) {
                    throw new RuntimeException('Report fixture disappeared during deployment.');
                }
                expect(array_intersect_key($after, $before))->toBe($before);
            }
            if ($existingReports) {
                expect(fileperms($reportsDirectory.'/22') & 0777)->toBe(0750)
                    ->and(fileperms($reportsDirectory.'/22/nested') & 0777)->toBe(0750)
                    ->and(fileperms($reportsDirectory.'/22/nested/report.pdf') & 0777)->toBe(0640);
            }
            if (! $existingReports) {
                expect(is_dir($reportsDirectory))->toBeTrue();
                expect(fileperms($reportsDirectory) & 0777)->toBe(0775);
            }
        }
    } finally {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($fixtureRoot, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            if (! $file instanceof SplFileInfo) {
                throw new RuntimeException('Unexpected fixture directory entry.');
            }
            if ($file->isDir()) {
                rmdir($file->getPathname());
            } else {
                unlink($file->getPathname());
            }
        }
        rmdir($fixtureRoot);
    }
})->with([
    'both keys and existing reports' => [['private', 'public'], true],
    'private key only' => [['private'], true],
    'public key only' => [['public'], true],
    'no keys' => [[], true],
    'missing reports parent' => [['private', 'public'], false],
]);

it('requires report provisioning before updating either production checkout', function () {
    $projectRoot = dirname(__DIR__, 2);
    foreach (['deployment/deploy.sh', '.github/workflows/deploy.yml'] as $path) {
        $script = file_get_contents($projectRoot.'/'.$path);
        expect($script)->toBeString();
        if (! is_string($script)) {
            throw new RuntimeException('Unable to read deployment entry point.');
        }
        $preflight = strpos($script, 'git show origin/main:deployment/check-report-permissions.sh | bash');
        $merge = strpos($script, 'git merge --ff-only origin/main');
        expect($preflight)->not->toBeFalse()
            ->and($merge)->not->toBeFalse()
            ->and($preflight)->toBeLessThan($merge)
            ->and($script)->toContain('set -euo pipefail');
    }
});

it('rejects unprovisioned and stale sessions without executing privileged commands', function (bool $group, bool $directories, bool $files, bool $allowed) {
    $fixtureRoot = sys_get_temp_dir().'/familyfund-preflight-'.bin2hex(random_bytes(8));
    mkdir($fixtureRoot, 0755, true);
    file_put_contents($fixtureRoot.'/id', "#!/bin/bash\nprintf '%s\\n' \"\$TEST_GROUPS\"\n");
    file_put_contents($fixtureRoot.'/sudo', <<<'BASH'
#!/bin/bash
set -euo pipefail
[[ "$1 $2" == '-n -l' ]] || exit 99
printf '%s\n' "$*" >> "$TEST_LOG"
case "$*" in
    *' -type d '*) exit "$TEST_DIRECTORIES" ;;
    *' -type f '*) exit "$TEST_FILES" ;;
    *) exit 99 ;;
esac
BASH);
    chmod($fixtureRoot.'/id', 0755);
    chmod($fixtureRoot.'/sudo', 0755);
    try {
        $process = new Process(['bash', dirname(__DIR__, 2).'/deployment/check-report-permissions.sh'], null, [
            'PATH' => $fixtureRoot.':'.getenv('PATH'),
            'TEST_GROUPS' => $group ? 'deployer www-data' : 'deployer',
            'TEST_DIRECTORIES' => $directories ? '0' : '1',
            'TEST_FILES' => $files ? '0' : '1',
            'TEST_LOG' => $fixtureRoot.'/commands',
        ]);
        $process->run();
        expect($process->isSuccessful())->toBe($allowed);
        if (! $allowed) {
            expect($process->getErrorOutput())->toContain('provision-report-permissions.sh', 'reconnect as deployer');
        }
        if (! $group) {
            expect(file_exists($fixtureRoot.'/commands'))->toBeFalse();
        }
    } finally {
        foreach (['id', 'sudo', 'commands'] as $file) {
            if (file_exists($fixtureRoot.'/'.$file)) {
                unlink($fixtureRoot.'/'.$file);
            }
        }
        rmdir($fixtureRoot);
    }
})->with([
    'old server' => [false, false, false, false],
    'group only' => [true, false, false, false],
    'missing file rule' => [true, true, false, false],
    'stale login after provisioning' => [false, true, true, false],
    'provisioned fresh session' => [true, true, true, true],
]);

it('validates report policy before installation and safely repeats provisioning', function (bool $valid) {
    $fixtureRoot = sys_get_temp_dir().'/familyfund-provision-'.bin2hex(random_bytes(8));
    mkdir($fixtureRoot, 0755, true);
    $policy = $fixtureRoot.'/familyfunds-report-permissions';
    file_put_contents($policy, 'previous policy');
    $script = file_get_contents(dirname(__DIR__, 2).'/deployment/provision-report-permissions.sh');
    if ($script === false) {
        throw new RuntimeException('Unable to read provisioning script.');
    }
    file_put_contents($fixtureRoot.'/provision.sh', str_replace(['/etc/sudoers.d', '"$EUID"'], [$fixtureRoot, '0'], $script));
    file_put_contents($fixtureRoot.'/visudo', "#!/bin/bash\nexit \"\$TEST_VALIDATION_STATUS\"\n");
    file_put_contents($fixtureRoot.'/usermod', "#!/bin/bash\nprintf '%s\\n' \"\$*\" >> \"\$TEST_LOG\"\n");
    chmod($fixtureRoot.'/visudo', 0755);
    chmod($fixtureRoot.'/usermod', 0755);
    try {
        for ($run = 0; $run < 2; $run++) {
            $process = new Process(['bash', $fixtureRoot.'/provision.sh'], null, [
                'PATH' => $fixtureRoot.':'.getenv('PATH'),
                'TEST_VALIDATION_STATUS' => $valid ? '0' : '1',
                'TEST_LOG' => $fixtureRoot.'/groups',
            ]);
            $process->run();
            expect($process->isSuccessful())->toBe($valid);
            clearstatcache();
            if ($valid) {
                expect(file_get_contents($policy))->toContain('chmod g+rx', 'chmod g+r')
                    ->and(fileperms($policy) & 0777)->toBe(0440)
                    ->and(file_get_contents($fixtureRoot.'/groups'))->toContain('-aG www-data deployer');
            } else {
                expect(file_get_contents($policy))->toBe('previous policy')
                    ->and(file_exists($fixtureRoot.'/groups'))->toBeFalse();
            }
            expect(glob($fixtureRoot.'/.familyfunds-report-permissions.*'))->toBe([]);
        }
    } finally {
        foreach (['familyfunds-report-permissions', 'provision.sh', 'visudo', 'usermod', 'groups'] as $file) {
            if (file_exists($fixtureRoot.'/'.$file)) {
                unlink($fixtureRoot.'/'.$file);
            }
        }
        rmdir($fixtureRoot);
    }
})->with([true, false]);
