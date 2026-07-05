<?php

declare(strict_types=1);

use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    config()->set('backup.backup.name', 'familyfund-production');
    config()->set('backup.google_drive.binary', '/usr/bin/rclone');
    config()->set('backup.google_drive.remote', 'familyfund_drive:FamilyFund/production/backups');
    config()->set('backup.google_drive.timeout', 3600);
});

test('google drive backup sync fails when rclone upload is disabled', function () {
    config()->set('backup.google_drive.enabled', false);

    $this->artisan('backups:sync-google-drive')
        ->expectsOutputToContain('Google Drive backup sync is disabled.')
        ->assertFailed();
});

test('google drive backup sync fails when remote is missing', function () {
    config()->set('backup.google_drive.enabled', true);
    config()->set('backup.google_drive.remote', '');

    $this->artisan('backups:sync-google-drive')
        ->expectsOutputToContain('Google Drive backup sync remote is missing.')
        ->assertFailed();
});

test('google drive backup sync fails when local backup directory is missing', function () {
    config()->set('backup.google_drive.enabled', true);
    Storage::fake('local');

    $this->artisan('backups:sync-google-drive')
        ->expectsOutputToContain('Local backup directory does not exist:')
        ->assertFailed();
});

test('google drive backup sync fails when no local backup archives exist', function () {
    config()->set('backup.google_drive.enabled', true);
    Storage::fake('local');

    Storage::disk('local')->makeDirectory('familyfund-production');

    $this->artisan('backups:sync-google-drive')
        ->expectsOutputToContain('No local backup archives were found in:')
        ->assertFailed();
});

test('google drive backup sync runs rclone sync and check commands', function () {
    config()->set('backup.google_drive.enabled', true);
    Storage::fake('local');

    Storage::disk('local')->put('familyfund-production/2026-07-05-023000.zip', 'encrypted zip bytes');
    Process::fake([
        '*' => Process::sequence()
            ->push(Process::result(output: 'sync ok'))
            ->push(Process::result(output: 'check ok')),
    ]);

    $source = Storage::disk('local')->path('familyfund-production');

    $this->artisan('backups:sync-google-drive')
        ->expectsOutputToContain('Google Drive backup sync completed.')
        ->assertSuccessful();

    Process::assertRan(fn (PendingProcess $process): bool => processCommandMatches($process, [
        '/usr/bin/rclone',
        'sync',
        $source,
        'familyfund_drive:FamilyFund/production/backups',
        '--create-empty-src-dirs',
        '--checksum',
        '--transfers',
        '4',
        '--checkers',
        '8',
    ]));

    Process::assertRan(fn (PendingProcess $process): bool => processCommandMatches($process, [
        '/usr/bin/rclone',
        'check',
        $source,
        'familyfund_drive:FamilyFund/production/backups',
        '--one-way',
        '--checksum',
    ]));
});

test('google drive backup sync supports dry runs and numeric string timeouts', function () {
    config()->set('backup.google_drive.enabled', true);
    config()->set('backup.google_drive.timeout', '12');
    Storage::fake('local');

    Storage::disk('local')->put('familyfund-production/2026-07-05-023000.zip', 'encrypted zip bytes');
    Process::fake([
        '*' => Process::sequence()
            ->push(Process::result(output: 'dry sync ok'))
            ->push(Process::result(output: 'check ok')),
    ]);

    $source = Storage::disk('local')->path('familyfund-production');

    $this->artisan('backups:sync-google-drive --dry-run')
        ->assertSuccessful();

    Process::assertRan(fn (PendingProcess $process): bool => $process->timeout === 12
        && processCommandMatches($process, [
            '/usr/bin/rclone',
            'sync',
            $source,
            'familyfund_drive:FamilyFund/production/backups',
            '--create-empty-src-dirs',
            '--checksum',
            '--transfers',
            '4',
            '--checkers',
            '8',
            '--dry-run',
        ]));
});

test('google drive backup sync falls back to default timeout for invalid config values', function () {
    config()->set('backup.google_drive.enabled', true);
    config()->set('backup.google_drive.timeout', 'soon');
    Storage::fake('local');

    Storage::disk('local')->put('familyfund-production/2026-07-05-023000.zip', 'encrypted zip bytes');
    Process::fake([
        '*' => Process::sequence()
            ->push(Process::result(output: 'sync ok'))
            ->push(Process::result(output: 'check ok')),
    ]);

    $this->artisan('backups:sync-google-drive')
        ->assertSuccessful();

    Process::assertRan(fn (PendingProcess $process): bool => $process->timeout === 3600
        && processCommandContains($process, 'sync'));
});

test('google drive backup sync returns failure when rclone cannot start', function () {
    config()->set('backup.google_drive.enabled', true);
    Storage::fake('local');

    Storage::disk('local')->put('familyfund-production/2026-07-05-023000.zip', 'encrypted zip bytes');
    Process::fake([
        '*' => new RuntimeException('rclone binary missing'),
    ]);

    $this->artisan('backups:sync-google-drive')
        ->expectsOutputToContain('rclone sync could not start: rclone binary missing')
        ->assertFailed();
});

test('google drive backup sync returns failure when rclone sync fails', function () {
    config()->set('backup.google_drive.enabled', true);
    Storage::fake('local');

    Storage::disk('local')->put('familyfund-production/2026-07-05-023000.zip', 'encrypted zip bytes');
    Process::fake([
        '*' => Process::result(output: 'sync stdout', errorOutput: 'sync failed', exitCode: 1),
    ]);

    $this->artisan('backups:sync-google-drive')
        ->expectsOutputToContain('rclone sync failed with exit code 1.')
        ->expectsOutputToContain('sync failed')
        ->expectsOutputToContain('sync stdout')
        ->assertFailed();

    Process::assertRanTimes(fn (PendingProcess $process): bool => processCommandContains($process, 'sync'), 1);
    Process::assertRanTimes(fn (PendingProcess $process): bool => processCommandContains($process, 'check'), 0);
});

test('google drive backup sync returns failure when rclone check fails', function () {
    config()->set('backup.google_drive.enabled', true);
    Storage::fake('local');

    Storage::disk('local')->put('familyfund-production/2026-07-05-023000.zip', 'encrypted zip bytes');
    Process::fake([
        '*' => Process::sequence()
            ->push(Process::result(output: 'sync ok'))
            ->push(Process::result(errorOutput: 'check failed', exitCode: 1)),
    ]);

    $this->artisan('backups:sync-google-drive')
        ->expectsOutputToContain('rclone check failed with exit code 1.')
        ->expectsOutputToContain('check failed')
        ->assertFailed();

    Process::assertRanTimes(fn (PendingProcess $process): bool => processCommandContains($process, 'sync'), 1);
    Process::assertRanTimes(fn (PendingProcess $process): bool => processCommandContains($process, 'check'), 1);
});

/**
 * @param  array<int, string>  $expected
 */
function processCommandMatches(PendingProcess $process, array $expected): bool
{
    return $process->command === $expected;
}

function processCommandContains(PendingProcess $process, string $argument): bool
{
    return is_array($process->command) && in_array($argument, $process->command, true);
}
