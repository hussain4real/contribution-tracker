<?php

declare(strict_types=1);

use App\Models\Contribution;
use App\Models\Family;
use App\Models\User;
use App\Notifications\ContributionReminderNotification;
use Illuminate\Support\Facades\Notification;

it('catches up a prior-month reminder in one family and cursor window using the actual delivery timestamp', function (): void {
    Notification::fake();
    $this->travelTo(now()->setDate(2026, 11, 3)->setTime(10, 0));
    $first = Family::factory()->create();
    $second = Family::factory()->create();
    $member = User::factory()->member()->employed()->create(['family_id' => $first->id]);
    $other = User::factory()->member()->employed()->create(['family_id' => $second->id]);
    $before = Contribution::factory()->forUser($member)->forMonth(2026, 10)->create();
    $selected = Contribution::factory()->forUser($member)->forMonth(2026, 10)->create(['user_id' => User::factory()->member()->employed()->create(['family_id' => $first->id])->id]);
    $outside = Contribution::factory()->forUser($other)->forMonth(2026, 10)->create();
    $arguments = ['--day' => 25, '--year' => 2026, '--month' => 10, '--family' => $first->id, '--after' => $before->id, '--through' => $selected->id];
    $this->artisan('contributions:remind', $arguments)->assertSuccessful();
    $this->artisan('contributions:remind', $arguments)->assertSuccessful();
    expect($selected->fresh()?->reminder_sent_at?->toDateString())->toBe('2026-11-03')
        ->and($before->fresh()?->reminder_sent_at)->toBeNull()
        ->and($outside->fresh()?->reminder_sent_at)->toBeNull();
    Notification::assertCount(1);
    Notification::assertSentTo($selected->user, ContributionReminderNotification::class);
});

it('refuses malformed maintenance cursor and month options', function (): void {
    $this->artisan('contributions:remind', ['--day' => 25, '--family' => 'invalid'])->assertFailed();
    $this->artisan('contributions:remind', ['--day' => 25, '--month' => 13])->assertFailed();
});
