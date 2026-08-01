<?php

declare(strict_types=1);

it('normalizes storage permissions before production deployment completes', function () {
    $projectRoot = dirname(__DIR__, 2);
    $script = file_get_contents($projectRoot.'/deployment/ensure-storage-permissions.sh');
    $deployScript = file_get_contents($projectRoot.'/deployment/deploy.sh');
    $workflow = file_get_contents($projectRoot.'/.github/workflows/deploy.yml');

    expect($script)
        ->toContain('chown -R deployer:www-data')
        ->toContain('find "${WRITABLE_PATHS[@]}" -type d -exec chmod 2775')
        ->toContain('find "${WRITABLE_PATHS[@]}" -type f -exec chmod 0664')
        ->toContain('storage/app/private/reports');

    expect($deployScript)->toContain('bash deployment/ensure-storage-permissions.sh "$APP_DIR"');
    expect($workflow)->toContain('bash deployment/ensure-storage-permissions.sh /var/www/contribution-tracker');
});
