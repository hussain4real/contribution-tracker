<?php

declare(strict_types=1);

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('contributions:generate')
    ->monthlyOn(1, '00:00')
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command('contributions:remind --day=25')
    ->monthlyOn(25, '08:00')
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command('contributions:remind --day=28')
    ->monthlyOn(28, '08:00')
    ->withoutOverlapping()
    ->onOneServer();

$backupEvents = [
    Schedule::command('backup:run --only-to-disk=local')
        ->name('backup-run-local')
        ->dailyAt('02:30')
        ->timezone('Asia/Qatar')
        ->environments(['production'])
        ->withoutOverlapping()
        ->onOneServer(),

    Schedule::command('backup:clean')
        ->name('backup-clean-local')
        ->dailyAt('03:15')
        ->timezone('Asia/Qatar')
        ->environments(['production'])
        ->withoutOverlapping()
        ->onOneServer(),

    Schedule::command('backups:sync-google-drive')
        ->name('backup-sync-google-drive')
        ->dailyAt('03:30')
        ->timezone('Asia/Qatar')
        ->environments(['production'])
        ->withoutOverlapping()
        ->onOneServer(),

    Schedule::command('backup:monitor')
        ->name('backup-monitor-local')
        ->dailyAt('08:00')
        ->timezone('Asia/Qatar')
        ->environments(['production'])
        ->withoutOverlapping()
        ->onOneServer(),
];

$backupNotificationEmail = config('backup.notifications.mail.to');

if (is_string($backupNotificationEmail) && trim($backupNotificationEmail) !== '') {
    foreach ($backupEvents as $backupEvent) {
        $backupEvent->emailOutputOnFailure($backupNotificationEmail);
    }
}
