<?php

declare(strict_types=1);

namespace FamilyFunds\D1Staging;

use Closure;
use League\Flysystem\FilesystemOperator;
use RuntimeException;
use Symfony\Component\Filesystem\Filesystem;

final class D1ProductionBackupSync
{
    /** @param Closure(list<string>): bool $execute */
    public function __construct(private FilesystemOperator $backups, private Closure $execute) {}

    /** @return array{checkpoint: ?string, files: int, bytes: int, encrypted_only: true, checksum_verified: true} */
    public function run(string $configuration, string $remote = 'familyfund_drive:FamilyFund/cloudflare-production/backups/live', ?string $checkpoint = null): array
    {
        if ($remote !== 'familyfund_drive:FamilyFund/cloudflare-production/backups/live'
            || ! is_file($configuration) || is_link($configuration) || (fileperms($configuration) & 0777) !== 0600) {
            throw new RuntimeException('Offsite sync requires its private configuration and separate Cloudflare backup folder.');
        }
        if ($checkpoint !== null && preg_match('/^cutover-[0-9]{8}t[0-9]{6}z$/D', $checkpoint) !== 1) {
            throw new RuntimeException('Offsite sync requires a valid explicit cutover checkpoint.');
        }
        $prefix = $checkpoint === null ? '' : 'checkpoints/'.$checkpoint.'/';
        $filesystem = new Filesystem;
        $directory = sys_get_temp_dir().'/familyfunds-offsite-'.bin2hex(random_bytes(16));
        $filesystem->mkdir($directory, 0700);
        $files = 0;
        $bytes = 0;
        $snapshot = false;
        $objects = 0;
        try {
            foreach ($this->backups->listContents($prefix, true) as $object) {
                if (! $object->isFile()) {
                    continue;
                }
                $path = $object->path();
                if (++$objects > 2000 || preg_match('#^(?:checkpoints/cutover-\d{8}t\d{6}z/)?(d1/\d{4}-\d{2}-\d{2}|files/[a-f0-9]{64})\.json\.aes-gcm$#D', $path, $match) !== 1) {
                    throw new RuntimeException('Unexpected or excessive encrypted backup set.');
                }
                if ($checkpoint === null && str_starts_with($path, 'checkpoints/')) {
                    continue;
                }
                if ($checkpoint !== null && ! str_starts_with($path, $prefix)) {
                    throw new RuntimeException('Offsite sync returned a different cutover checkpoint.');
                }
                $files++;
                $destination = $directory.'/'.$path;
                $filesystem->mkdir(dirname($destination), 0700);
                $input = $this->backups->readStream($path);
                $output = fopen($destination, 'x');
                if (! is_resource($input) || $output === false) {
                    if (is_resource($input)) {
                        fclose($input);
                    }
                    throw new RuntimeException('Could not stage the encrypted backup.');
                }
                chmod($destination, 0600);
                try {
                    $size = stream_copy_to_stream($input, $output, 64 * 1024 * 1024 + 1);
                    if ($size === false || $size > 64 * 1024 * 1024 || ($bytes += $size) > 512 * 1024 * 1024) {
                        throw new RuntimeException('Encrypted backup exceeds the isolated copy budget.');
                    }
                } finally {
                    fclose($input);
                    fclose($output);
                }
                $contents = file_get_contents($destination);
                if ($contents === false) {
                    throw new RuntimeException('Could not read the encrypted backup.');
                }
                $envelope = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
                $relativePath = $match[1];
                $kind = str_starts_with($relativePath, 'd1/') ? 'database' : 'file:'.substr($relativePath, 6, 64);
                if (! is_array($envelope) || ($envelope['version'] ?? null) !== 1 || ($envelope['database'] ?? null) !== 'familyfunds-production'
                    || ($envelope['kind'] ?? null) !== $kind || ! is_string($envelope['iv'] ?? null)
                    || strlen(base64_decode($envelope['iv'], true) ?: '') !== 12
                    || ! is_string($envelope['ciphertext'] ?? null) || strlen(base64_decode($envelope['ciphertext'], true) ?: '') < 16
                    || ! is_string($envelope['sha256'] ?? null) || preg_match('/^[a-f0-9]{64}$/D', $envelope['sha256']) !== 1) {
                    throw new RuntimeException('Offsite sync accepts only recognized encrypted backup envelopes.');
                }
                $snapshot = $snapshot || $kind === 'database';
            }
            if (! $snapshot) {
                throw new RuntimeException('Refusing offsite sync without a production database snapshot.');
            }
            foreach ([
                ['/usr/bin/rclone', 'copy', $directory, $remote, '--config', $configuration, '--checksum', '--fast-list', '--immutable', '--transfers', '2', '--checkers', '4', '--retries', '2', '--retries-sleep', '1s', '--low-level-retries', '10', '--tpslimit', '4', '--tpslimit-burst', '1', '--drive-pacer-burst', '1', '--contimeout', '5s', '--timeout', '20s'],
                ['/usr/bin/rclone', 'check', $directory, $remote, '--config', $configuration, '--one-way', '--checksum', '--fast-list', '--checkers', '4', '--retries', '2', '--retries-sleep', '1s', '--low-level-retries', '10', '--tpslimit', '4', '--tpslimit-burst', '1', '--drive-pacer-burst', '1', '--contimeout', '5s', '--timeout', '20s'],
            ] as $command) {
                if (! ($this->execute)($command)) {
                    throw new RuntimeException('Production offsite backup copy or checksum verification failed.');
                }
            }

            return ['checkpoint' => $checkpoint, 'files' => $files, 'bytes' => $bytes, 'encrypted_only' => true, 'checksum_verified' => true];
        } finally {
            $filesystem->remove($directory);
        }
    }
}
