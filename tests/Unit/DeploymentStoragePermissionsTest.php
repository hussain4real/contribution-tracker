<?php

declare(strict_types=1);

it('normalizes storage permissions before production deployment completes', function () {
    $projectRoot = dirname(__DIR__, 2);
    $script = file_get_contents($projectRoot.'/deployment/ensure-storage-permissions.sh');
    $deployScript = file_get_contents($projectRoot.'/deployment/deploy.sh');
    $setupScript = file_get_contents($projectRoot.'/deployment/setup-server.sh');
    $workflow = file_get_contents($projectRoot.'/.github/workflows/deploy.yml');

    if ($workflow === false) {
        throw new RuntimeException('Unable to read the production deployment workflow.');
    }

    expect($script)
        ->toContain('chown -R deployer:www-data')
        ->toContain('find "${WRITABLE_PATHS[@]}" -type d -exec /usr/bin/chmod 2775')
        ->toContain('find "${WRITABLE_PATHS[@]}" -type f -exec /usr/bin/chmod 0664')
        ->toContain('storage/app/private/reports');

    expect($setupScript)
        ->toContain('/etc/sudoers.d/familyfunds-storage-permissions')
        ->toContain('/usr/bin/chown -R deployer\:www-data /var/www/contribution-tracker/storage /var/www/contribution-tracker/bootstrap/cache')
        ->toContain('/usr/bin/install -d -o deployer -g www-data -m 2775 /var/www/contribution-tracker/storage/app/private/reports');

    expect($deployScript)->toContain('bash deployment/ensure-storage-permissions.sh "$APP_DIR"');
    expect($workflow)
        ->toContain('name: Bootstrap writable-path permissions')
        ->toContain('username: root')
        ->toContain('/usr/bin/chown -R deployer\:www-data /var/www/contribution-tracker/storage /var/www/contribution-tracker/bootstrap/cache')
        ->toContain('visudo -cf /etc/sudoers.d/familyfunds-storage-permissions')
        ->toContain('bash deployment/ensure-storage-permissions.sh /var/www/contribution-tracker');

    $bootstrapPosition = strpos($workflow, 'name: Bootstrap writable-path permissions');
    $deployPosition = strpos($workflow, 'name: Deploy to Server');

    if ($bootstrapPosition === false || $deployPosition === false) {
        throw new RuntimeException('Expected the permission bootstrap and deployment steps to exist.');
    }

    expect($bootstrapPosition)->toBeLessThan($deployPosition);
});
