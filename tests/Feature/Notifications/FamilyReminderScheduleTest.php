<?php

declare(strict_types=1);

use App\Models\Contribution;
use App\Models\Family;
use App\Models\User;
use App\Notifications\ContributionReminderNotification;
use Carbon\Carbon;
use Illuminate\Support\Facades\Notification;

it('allows a family admin to configure reminder days time and channels', function () {
    $this->travelTo(Carbon::parse('2026-10-03 12:00:00', 'UTC'));
    $family = Family::factory()->create();
    $admin = User::factory()->admin()->create(['family_id' => $family->id]);
    $member = User::factory()->member()->create(['family_id' => $family->id]);
    $url = route('family.reminder-settings.update', ['current_family' => $family->slug]);
    $settings = ['reminder_day' => 10, 'follow_up_day' => 20, 'time' => '14:35', 'channels' => ['mail']];
    $this->actingAs($member)->put($url, $settings)->assertForbidden();
    $this->actingAs($admin)->put($url, [...$settings, 'follow_up_day' => 9])->assertSessionHasErrors('follow_up_day');
    $this->actingAs($admin)->put($url, $settings)->assertRedirect()->assertSessionHasNoErrors();
    expect($family->fresh()?->reminder_settings)->toMatchArray([...$settings, 'effective_from' => '2026-11']);
    expect($family->fresh()?->reminderSettingsForMonth('2026-10')['reminder_day'])->toBe(25)
        ->and($family->fresh()?->reminderSettingsForMonth('2026-11')['reminder_day'])->toBe(10);
    $this->travelTo(Carbon::parse('2026-11-03 12:00:00', 'UTC'));
    $this->actingAs($admin)->put($url, [...$settings, 'reminder_day' => 12])->assertRedirect()->assertSessionHasNoErrors();
    expect($family->fresh()?->reminderSettingsForMonth('2026-11')['reminder_day'])->toBe(10)
        ->and($family->fresh()?->reminderSettingsForMonth('2026-12')['reminder_day'])->toBe(12)
        ->and($family->fresh()?->reminderSettingsForMonth('2026-10')['reminder_day'])->toBe(25);
});

it('sends configured reminders after their UTC boundaries without duplicate scheduling', function () {
    Notification::fake();
    $family = Family::factory()->create(['reminder_settings' => [
        'reminder_day' => 10, 'follow_up_day' => 20, 'time' => '14:35',
        'channels' => ['mail'], 'effective_from' => '2026-10',
    ]]);
    $member = User::factory()->member()->employed()->create(['family_id' => $family->id]);
    $contribution = Contribution::factory()->forUser($member)->forMonth(2026, 10)->create();
    $this->travelTo(Carbon::parse('2026-10-10 14:34:00', 'UTC'));
    $this->artisan('contributions:dispatch-due-reminders')->assertSuccessful();
    Notification::assertNothingSent();
    $this->travelTo(Carbon::parse('2026-10-10 14:35:00', 'UTC'));
    $this->artisan('contributions:dispatch-due-reminders')->assertSuccessful();
    $this->artisan('contributions:dispatch-due-reminders')->assertSuccessful();
    Notification::assertSentToTimes($member, ContributionReminderNotification::class, 1);
    expect($contribution->fresh()?->reminder_sent_at)->not->toBeNull();
    $this->travelTo(Carbon::parse('2026-10-20 14:35:00', 'UTC'));
    $this->artisan('contributions:dispatch-due-reminders')->assertSuccessful();
    Notification::assertSentToTimes($member, ContributionReminderNotification::class, 2);
});
