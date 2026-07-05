<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Throwable;

#[Signature('backups:sync-google-drive {--dry-run : Show rclone actions without changing Google Drive}')]
#[Description('Sync retained local backups to Google Drive through rclone')]
class SyncBackupsToGoogleDrive extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        if (! (bool) config('backup.google_drive.enabled')) {
            $this->error('Google Drive backup sync is disabled. Set BACKUP_RCLONE_ENABLED=true to enable it.');

            return self::FAILURE;
        }

        $remote = $this->stringConfig('backup.google_drive.remote');

        if ($remote === '') {
            $this->error('Google Drive backup sync remote is missing. Set BACKUP_RCLONE_REMOTE.');

            return self::FAILURE;
        }

        $binary = $this->stringConfig('backup.google_drive.binary', '/usr/bin/rclone');
        $timeout = max(1, $this->integerConfig('backup.google_drive.timeout', 3600));
        $source = $this->localBackupPath();

        if (! File::isDirectory($source)) {
            $this->error("Local backup directory does not exist: {$source}");

            return self::FAILURE;
        }

        if ($this->backupArchives($source) === []) {
            $this->error("No local backup archives were found in: {$source}");

            return self::FAILURE;
        }

        $syncCommand = [
            $binary,
            'sync',
            $source,
            $remote,
            '--create-empty-src-dirs',
            '--checksum',
            '--transfers',
            '4',
            '--checkers',
            '8',
        ];

        if ((bool) $this->option('dry-run')) {
            $syncCommand[] = '--dry-run';
        }

        if (! $this->runRclone($syncCommand, $timeout, 'sync')) {
            return self::FAILURE;
        }

        $checkCommand = [
            $binary,
            'check',
            $source,
            $remote,
            '--one-way',
            '--checksum',
        ];

        if (! $this->runRclone($checkCommand, $timeout, 'check')) {
            return self::FAILURE;
        }

        $this->info('Google Drive backup sync completed.');

        return self::SUCCESS;
    }

    private function localBackupPath(): string
    {
        return Storage::disk('local')->path($this->stringConfig('backup.backup.name'));
    }

    /**
     * @return array<int, string>
     */
    private function backupArchives(string $source): array
    {
        $archives = glob($source.DIRECTORY_SEPARATOR.'*.zip');

        return $archives === false ? [] : $archives;
    }

    /**
     * @param  array<int, string>  $command
     */
    private function runRclone(array $command, int $timeout, string $label): bool
    {
        $this->info("Running rclone {$label}...");

        try {
            $result = Process::timeout($timeout)->run($command);
        } catch (Throwable $exception) {
            $this->error("rclone {$label} could not start: {$exception->getMessage()}");

            return false;
        }

        if ($result->failed()) {
            $this->reportFailedProcess($label, $result);

            return false;
        }

        $this->line(trim($result->output()));

        return true;
    }

    private function reportFailedProcess(string $label, ProcessResult $result): void
    {
        $this->error("rclone {$label} failed with exit code {$result->exitCode()}.");

        $errorOutput = trim($result->errorOutput());
        $output = trim($result->output());

        if ($errorOutput !== '') {
            $this->error($errorOutput);
        }

        if ($output !== '') {
            $this->line($output);
        }
    }

    private function stringConfig(string $key, string $default = ''): string
    {
        $value = config($key, $default);

        return is_string($value) ? trim($value) : $default;
    }

    private function integerConfig(string $key, int $default): int
    {
        $value = config($key, $default);

        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && ctype_digit($value)) {
            return (int) $value;
        }

        return $default;
    }
}
