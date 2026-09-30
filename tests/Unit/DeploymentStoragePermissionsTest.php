<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

it('normalizes writable paths without changing report access or exposing Passport keys', function () {
    $projectRoot = dirname(__DIR__, 2);
    $script = file_get_contents($projectRoot.'/deployment/ensure-storage-permissions.sh');
    $deployScript = file_get_contents($projectRoot.'/deployment/deploy.sh');
    $setupScript = file_get_contents($projectRoot.'/deployment/setup-server.sh');
    $workflow = file_get_contents($projectRoot.'/.github/workflows/deploy.yml');

    if ($script === false || $deployScript === false || $setupScript === false || $workflow === false) {
        throw new RuntimeException('Unable to read the deployment permission fixtures.');
    }

    expect($script)
        ->toContain('REPORTS_DIR="$APP_DIR/storage/app/private/reports"')
        ->not->toContain('chown -R')
        ->toContain('find "${WRITABLE_PATHS[@]}" -path "$REPORTS_DIR" -prune -o -exec /usr/bin/chown -h deployer:www-data {} +')
        ->toContain('find "${WRITABLE_PATHS[@]}" -path "$REPORTS_DIR" -prune -o -type d -exec /usr/bin/chmod 2775 {} +')
        ->toContain('find "${WRITABLE_PATHS[@]}" -path "$REPORTS_DIR" -prune -o -path "$APP_DIR/storage/oauth-private.key" -prune -o -path "$APP_DIR/storage/oauth-public.key" -prune -o -type f -exec /usr/bin/chmod 0664 {} +')
        ->toContain('if [[ ! -e "$REPORTS_DIR" && ! -L "$REPORTS_DIR" ]]; then');

    foreach (['private', 'public'] as $keyType) {
        $keyCommand = '/usr/bin/chmod 0640 "$APP_DIR/storage/oauth-'.$keyType.'.key"';
        $normalizationCommand = 'find "${WRITABLE_PATHS[@]}" -path "$REPORTS_DIR" -prune -o -path "$APP_DIR/storage/oauth-private.key"';

        expect($script)
            ->toContain($keyCommand)
            ->not->toContain('sudo '.$keyCommand);
        expect(strpos($script, $keyCommand))->toBeLessThan(strpos($script, $normalizationCommand));
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
        ->not->toContain('/usr/bin/chown -R deployer\\:www-data /var/www/contribution-tracker/storage')
        ->not->toContain('/usr/bin/chmod 0640 /var/www/contribution-tracker/storage/oauth-private.key')
        ->not->toContain('/usr/bin/chmod 0640 /var/www/contribution-tracker/storage/oauth-public.key')
        ->toContain('/usr/bin/install -d -o deployer -g www-data -m 2775 /var/www/contribution-tracker/storage/app/private/reports');

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

    foreach ($keys as $keyType) {
        $keyPath = $applicationRoot.'/storage/oauth-'.$keyType.'.key';
        file_put_contents($keyPath, 'synthetic fixture, not a key');
        chmod($keyPath, 0664);
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
            $reportPaths[$path] = array_intersect_key($metadata, array_flip(['ino', 'mode', 'uid', 'gid', 'mtime', 'ctime']));
        }
    }

    // Execute real find/chmod; replace privileged ownership and install with spies.
    $chmodBinary = is_file('/usr/bin/chmod') ? '/usr/bin/chmod' : '/bin/chmod';
    $script = file_get_contents(dirname(__DIR__, 2).'/deployment/ensure-storage-permissions.sh');
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

            foreach ($keys as $keyType) {
                expect(fileperms($applicationRoot.'/storage/oauth-'.$keyType.'.key') & 0777)->toBe(0640);
            }
            expect(fileperms($applicationRoot.'/storage/logs/example.log') & 0777)->toBe(0664);
            expect(fileperms($applicationRoot.'/storage/logs') & 07777)->toBe(02775);
            expect(file_get_contents($commandLog))->not->toContain($reportsDirectory);
            expect(file_get_contents($commandLog))->toContain($applicationRoot.'/storage/logs/example.log');

            foreach ($reportPaths as $path => $before) {
                $after = stat($path);
                expect(array_intersect_key($after, $before))->toBe($before);
            }
            if (! $existingReports) {
                expect(is_dir($reportsDirectory))->toBeTrue();
                expect(fileperms($reportsDirectory) & 07777)->toBe(02775);
            }
        }
    } finally {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($fixtureRoot, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
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
