<?php

use App\Models\Contribution;
use App\Models\Family;
use App\Models\Payment;
use App\Models\User;
use App\Notifications\ContributionReminderNotification;
use Illuminate\Support\Facades\Notification;

describe('Send Contribution Reminders Command', function () {
    it('sends reminder notifications to members with unpaid contributions', function () {
        Notification::fake();

        $family = Family::factory()->create();
        $member = User::factory()->member()->employed()->create(['family_id' => $family->id]);
        Contribution::factory()->forUser($member)->currentMonth()->create();

        $this->artisan('contributions:remind', ['--day' => 25])
            ->assertSuccessful();

        Notification::assertSentTo($member, ContributionReminderNotification::class, function ($notification) {
            return $notification->type === 'reminder';
        });
    });

    it('sends follow_up notifications when day is 28', function () {
        Notification::fake();

        $family = Family::factory()->create();
        $member = User::factory()->member()->employed()->create(['family_id' => $family->id]);
        Contribution::factory()->forUser($member)->currentMonth()->create();

        $this->artisan('contributions:remind', ['--day' => 28])
            ->assertSuccessful();

        Notification::assertSentTo($member, ContributionReminderNotification::class, function ($notification) {
            return $notification->type === 'follow_up';
        });
    });

    it('skips members who have fully paid', function () {
        Notification::fake();

        $family = Family::factory()->create();
        $member = User::factory()->member()->employed()->create(['family_id' => $family->id]);
        $contribution = Contribution::factory()->forUser($member)->currentMonth()->create([
            'expected_amount' => 4000,
        ]);

        Payment::factory()->create([
            'contribution_id' => $contribution->id,
            'amount' => 4000,
            'paid_at' => now(),
        ]);

        $this->artisan('contributions:remind', ['--day' => 25])
            ->assertSuccessful();

        Notification::assertNotSentTo($member, ContributionReminderNotification::class);
    });

    it('includes members with partial payments', function () {
        Notification::fake();

        $family = Family::factory()->create();
        $member = User::factory()->member()->employed()->create(['family_id' => $family->id]);
        $contribution = Contribution::factory()->forUser($member)->currentMonth()->create([
            'expected_amount' => 4000,
        ]);

        Payment::factory()->create([
            'contribution_id' => $contribution->id,
            'amount' => 1000,
            'paid_at' => now(),
        ]);

        $this->artisan('contributions:remind', ['--day' => 25])
            ->assertSuccessful();

        Notification::assertSentTo($member, ContributionReminderNotification::class);
    });

    it('skips archived members', function () {
        Notification::fake();

        $family = Family::factory()->create();
        $member = User::factory()->member()->employed()->archived()->create(['family_id' => $family->id]);
        Contribution::factory()->forUser($member)->currentMonth()->create();

        $this->artisan('contributions:remind', ['--day' => 25])
            ->assertSuccessful();

        Notification::assertNotSentTo($member, ContributionReminderNotification::class);
    });

    it('sends to multiple families', function () {
        Notification::fake();

        $family1 = Family::factory()->create();
        $family2 = Family::factory()->create();
        $member1 = User::factory()->member()->employed()->create(['family_id' => $family1->id]);
        $member2 = User::factory()->member()->employed()->create(['family_id' => $family2->id]);

        Contribution::factory()->forUser($member1)->currentMonth()->create();
        Contribution::factory()->forUser($member2)->currentMonth()->create();

        $this->artisan('contributions:remind', ['--day' => 25])
            ->assertSuccessful();

        Notification::assertSentTo($member1, ContributionReminderNotification::class);
        Notification::assertSentTo($member2, ContributionReminderNotification::class);
    });
});
