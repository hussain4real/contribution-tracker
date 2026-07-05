<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Event;
use Illuminate\Support\Facades\Schedule;
use Spatie\Backup\Notifications\Notifications\BackupHasFailedNotification;
use Spatie\Backup\Notifications\Notifications\BackupWasSuccessfulNotification;
use Spatie\Backup\Notifications\Notifications\CleanupHasFailedNotification;
use Spatie\Backup\Notifications\Notifications\CleanupWasSuccessfulNotification;
use Spatie\Backup\Notifications\Notifications\HealthyBackupWasFoundNotification;
use Spatie\Backup\Notifications\Notifications\UnhealthyBackupWasFoundNotification;
use Spatie\DbDumper\Compressors\GzipCompressor;

test('backup sources include only irreplaceable production data', function () {
    expect(config('backup.backup.source.databases'))->toBe([
        config('database.default'),
    ])->and(config('backup.backup.source.files.include'))->toBe([
        base_path('.env'),
        storage_path('app'),
        storage_path('oauth-private.key'),
        storage_path('oauth-public.key'),
    ]);
});

test('backup sources exclude noisy generated and framework paths', function () {
    expect(config('backup.backup.source.files.exclude'))->toEqualCanonicalizing([
        base_path('.git'),
        base_path('bootstrap/ssr'),
        base_path('public/build'),
        base_path('public/hot'),
        base_path('resources/js/actions'),
        base_path('resources/js/routes'),
        base_path('resources/js/wayfinder'),
        base_path('vendor'),
        base_path('node_modules'),
        storage_path('app/backup-temp'),
        storage_path('debugbar'),
        storage_path('framework'),
        storage_path('logs'),
        storage_path('pail'),
    ]);
});

test('backup archives stay local, encrypted, compressed, and verified', function () {
    expect(config('backup.backup.destination.disks'))->toBe(['local'])
        ->and(config('backup.backup'))->toHaveKey('password')
        ->and(config('backup.backup.encryption'))->toBe('default')
        ->and(config('backup.backup.verify_backup'))->toBeTrue()
        ->and(config('backup.backup.database_dump_compressor'))->toBe(GzipCompressor::class);
});

test('backup retention keeps fourteen daily backups with weekly and monthly history capped at fifty gigabytes', function () {
    expect(config('backup.cleanup.default_strategy'))->toMatchArray([
        'keep_all_backups_for_days' => 14,
        'keep_daily_backups_for_days' => 0,
        'keep_weekly_backups_for_weeks' => 8,
        'keep_monthly_backups_for_months' => 6,
        'keep_yearly_backups_for_years' => 0,
        'delete_oldest_backups_when_using_more_megabytes_than' => 50000,
    ]);

    expect(config('backup.monitor_backups.0'))->toMatchArray([
        'name' => config('backup.backup.name'),
        'disks' => ['local'],
    ])->and(config('backup.monitor_backups.0.health_checks'))->toContain(50000);
});

test('backup notifications are failure only', function () {
    $notifications = config('backup.notifications.notifications');

    expect($notifications)->toHaveKeys([
        BackupHasFailedNotification::class,
        CleanupHasFailedNotification::class,
        UnhealthyBackupWasFoundNotification::class,
    ])->and($notifications)->not->toHaveKeys([
        BackupWasSuccessfulNotification::class,
        CleanupWasSuccessfulNotification::class,
        HealthyBackupWasFoundNotification::class,
    ]);
});

test('backup scheduler runs production backup workflow in qatar time', function () {
    $events = collect(Schedule::events());

    expect($events->contains(fn (Event $event): bool => scheduledBackupEventExists(
        event: $event,
        command: 'backup:run --only-to-disk=local',
        expression: '30 2 * * *',
    )))->toBeTrue()
        ->and($events->contains(fn (Event $event): bool => scheduledBackupEventExists(
            event: $event,
            command: 'backup:clean',
            expression: '15 3 * * *',
        )))->toBeTrue()
        ->and($events->contains(fn (Event $event): bool => scheduledBackupEventExists(
            event: $event,
            command: 'backups:sync-google-drive',
            expression: '30 3 * * *',
        )))->toBeTrue()
        ->and($events->contains(fn (Event $event): bool => scheduledBackupEventExists(
            event: $event,
            command: 'backup:monitor',
            expression: '0 8 * * *',
        )))->toBeTrue();
});

function scheduledBackupEventExists(Event $event, string $command, string $expression): bool
{
    return str_contains($event->command ?? '', $command)
        && $event->expression === $expression
        && $event->timezone === 'Asia/Qatar'
        && $event->environments === ['production']
        && $event->withoutOverlapping === true
        && $event->onOneServer === true;
}
