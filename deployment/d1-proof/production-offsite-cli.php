<?php

declare(strict_types=1);

use Aws\S3\S3Client;
use FamilyFunds\D1Staging\D1ProductionBackupSync;
use League\Flysystem\AwsS3V3\AwsS3V3Adapter;
use League\Flysystem\Filesystem;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

$vendor = '/var/www/html/vendor/autoload.php';
$copier = __DIR__.'/D1ProductionBackupSync.php';
if (! is_file($vendor) || ! is_file($copier)) {
    throw new RuntimeException('Missing isolated offsite runtime.');
}
require $vendor;
require $copier;

umask(0077);
$configuration = '/run/familyfunds-offsite-rclone.conf';
$exitCode = 0;
$diagnostics = ['stage' => 'configuration', 'error_code' => 'unknown', 'process' => null];
$phases = [];
$started = microtime(true);
$deadline = $started + 145;
try {
    $encoded = getenv('FF_RCLONE_CONFIG');
    $bytes = is_string($encoded) ? base64_decode($encoded, true) : false;
    if ($bytes === false || strlen($bytes) < 10 || strlen($bytes) > 12000) {
        throw new RuntimeException('Invalid isolated offsite configuration.');
    }
    if (file_put_contents($configuration, $bytes) !== strlen($bytes)) {
        throw new RuntimeException('Could not install isolated offsite configuration.');
    }
    chmod($configuration, 0600);
    putenv('FF_RCLONE_CONFIG');
    $diagnostics['stage'] = 'r2-stage';
    $storage = new Filesystem(new AwsS3V3Adapter(new S3Client([
        'version' => 'latest', 'region' => 'auto',
        'endpoint' => 'https://40344948b73fa9da3008958fdc3faf12.r2.cloudflarestorage.com',
        'use_path_style_endpoint' => true,
        'request_checksum_calculation' => 'when_required', 'response_checksum_validation' => 'when_required',
        'credentials' => ['key' => getenv('BACKUP_R2_ACCESS_KEY_ID'), 'secret' => getenv('BACKUP_R2_SECRET_ACCESS_KEY')],
        'http' => ['connect_timeout' => 5, 'timeout' => 25],
    ]), 'familyfunds-production-backups', 'private/production-backups'));
    putenv('BACKUP_R2_ACCESS_KEY_ID');
    putenv('BACKUP_R2_SECRET_ACCESS_KEY');
    $checkpoint = getenv('BACKUP_CHECKPOINT');
    $result = (new D1ProductionBackupSync($storage, function (array $command) use (&$diagnostics, &$phases, $started, $deadline): bool {
        if ($phases === []) {
            $phases[] = ['action' => 'r2-stage', 'seconds' => round(microtime(true) - $started, 3)];
        }
        $diagnostics['stage'] = $command[1] === 'copy' ? 'drive-copy' : 'drive-check';
        $diagnostics['process'] = ['action' => $command[1], 'exit_code' => null, 'seconds' => 0, 'categories' => [], 'words' => []];
        $remaining = $deadline - microtime(true) - 3;
        if ($remaining < 5) {
            $diagnostics['error_code'] = 'transfer_deadline';
            throw new RuntimeException('Offsite transfer deadline exhausted.');
        }
        $process = new Process($command, timeout: min($command[1] === 'copy' ? 95 : 65, $remaining));
        $started = microtime(true);
        try {
            $code = $process->run(function (string $type, string $chunk) use (&$diagnostics, $process): void {
                $allowed = ['error', 'unknown', 'flag', 'invalid', 'failed', 'create', 'filesystem', 'drive', 'oauth', 'token', 'authorization', 'expired', 'refresh', 'revoked', 'scope', 'quota', 'mismatch', 'immutable', 'different', 'copy', 'transfer', 'corrupted', 'not', 'found', 'config', 'directory', 'cannot', 'unrecognized', 'unexpected', 'permission', 'timed', 'timeout', 'parse', 'retries', 'level', 'retry', 'connection', 'network', 'protocol', 'tls', 'certificate', 'remote', 'local', 'command', 'server', 'client', 'http', 'file', 'hash', 'checksum', 'update', 'size', 'length', 'path', 'sleep', 'read', 'write', 'digest', 'access', 'denied', 'credentials', 'endpoint', 'context', 'deadline', 'exceeded', 'fatal'];
                foreach (preg_split('/[^a-z]+/', strtolower($chunk)) ?: [] as $word) {
                    if (in_array($word, $allowed, true) && ! in_array($word, $diagnostics['process']['words'], true) && count($diagnostics['process']['words']) < 30) {
                        $diagnostics['process']['words'][] = $word;
                    }
                }
                foreach (['immutable' => 'immutable', 'differ' => 'different', 'invalid_grant' => 'invalid_grant', 'permission' => 'permission', 'unauthorized' => 'unauthorized', 'not found' => 'not_found', 'rate limit' => 'rate_limit', 'timed out' => 'timeout', 'failed to copy' => 'copy_error', 'queries per minute' => 'query_quota', 'ratelimitexceeded' => 'rate_limit', 'storagequotaexceeded' => 'storage_quota', 'disk quota exceeded' => 'disk_quota', 'quota metric' => 'api_quota'] as $pattern => $category) {
                    if (str_contains(strtolower($chunk), $pattern) && ! in_array($category, $diagnostics['process']['categories'], true)) {
                        $diagnostics['process']['categories'][] = $category;
                    }
                }
                $process->clearOutput();
                $process->clearErrorOutput();
            });
            $diagnostics['process']['exit_code'] = $code;

            return $code === 0;
        } catch (ProcessTimedOutException $error) {
            $diagnostics['error_code'] = 'process_timeout';
            throw $error;
        } finally {
            $diagnostics['process']['seconds'] = round(microtime(true) - $started, 3);
            $phases[] = $diagnostics['process'];
        }
    }))->run($configuration, checkpoint: is_string($checkpoint) && $checkpoint !== '' ? $checkpoint : null);
    $result['phases'] = $phases;
    echo json_encode($result, JSON_THROW_ON_ERROR)."\n";
} catch (Throwable $error) {
    $known = [
        'Unexpected or excessive encrypted backup set.' => 'backup_set',
        'Could not stage the encrypted backup.' => 'staging',
        'Encrypted backup exceeds the isolated copy budget.' => 'size_budget',
        'Offsite sync accepts only recognized encrypted backup envelopes.' => 'envelope',
        'Refusing offsite sync without a production database snapshot.' => 'missing_snapshot',
        'Production offsite backup copy or checksum verification failed.' => 'transfer',
    ];
    $diagnostics['error_code'] = $known[$error->getMessage()] ?? $diagnostics['error_code'];
    echo json_encode(['exit_code' => 1, 'diagnostics' => $diagnostics], JSON_THROW_ON_ERROR)."\n";
    $exitCode = 1;
} finally {
    if (is_file($configuration)) {
        unlink($configuration);
    }
}
exit($exitCode);
