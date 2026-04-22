<?php

use App\Models\Contribution;
use App\Models\Family;
use App\Models\User;
use App\Notifications\ContributionReminderNotification;
use Illuminate\Support\Facades\Notification;

describe('Manual email contribution reminder', function () {
    it('sends an email-only reminder when admin triggers it', function () {
        Notification::fake();

        $family = Family::factory()->create();
        $admin = User::factory()->admin()->create(['family_id' => $family->id]);
        $member = User::factory()
            ->member()
            ->employed()
            ->create(['family_id' => $family->id]);
        $contribution = Contribution::factory()
            ->forUser($member)
            ->currentMonth()
            ->create(['expected_amount' => 5000]);

        $this->actingAs($admin)
            ->post("/contributions/{$contribution->id}/email-reminder")
            ->assertRedirect();

        Notification::assertSentTo(
            $member,
            ContributionReminderNotification::class,
            function (ContributionReminderNotification $notification) use ($member): bool {
                return $notification->via($member) === ['mail'];
            },
        );
    });

    it('allows financial secretary to send an email reminder', function () {
        Notification::fake();

        $family = Family::factory()->create();
        $fs = User::factory()->financialSecretary()->create(['family_id' => $family->id]);
        $member = User::factory()
            ->member()
            ->employed()
            ->create(['family_id' => $family->id]);
        $contribution = Contribution::factory()
            ->forUser($member)
            ->currentMonth()
            ->create(['expected_amount' => 5000]);

        $this->actingAs($fs)
            ->post("/contributions/{$contribution->id}/email-reminder")
            ->assertRedirect();

        Notification::assertSentTo($member, ContributionReminderNotification::class);
    });

    it('forbids regular members from sending an email reminder', function () {
        Notification::fake();

        $family = Family::factory()->create();
        $sender = User::factory()->member()->employed()->create(['family_id' => $family->id]);
        $target = User::factory()
            ->member()
            ->employed()
            ->create(['family_id' => $family->id]);
        $contribution = Contribution::factory()
            ->forUser($target)
            ->currentMonth()
            ->create();

        $this->actingAs($sender)
            ->post("/contributions/{$contribution->id}/email-reminder")
            ->assertForbidden();

        Notification::assertNothingSent();
    });

    it('forbids admins from sending email reminders for contributions outside their family', function () {
        Notification::fake();

        $familyA = Family::factory()->create();
        $familyB = Family::factory()->create();
        $admin = User::factory()->admin()->create(['family_id' => $familyA->id]);
        $member = User::factory()
            ->member()
            ->employed()
            ->create(['family_id' => $familyB->id]);
        $contribution = Contribution::factory()
            ->forUser($member)
            ->currentMonth()
            ->create();

        $this->actingAs($admin)
            ->post("/contributions/{$contribution->id}/email-reminder")
            ->assertForbidden();

        Notification::assertNothingSent();
    });

    it('redirects back with an error when the member has no email address', function () {
        Notification::fake();

        $family = Family::factory()->create();
        $admin = User::factory()->admin()->create(['family_id' => $family->id]);
        $member = User::factory()
            ->member()
            ->employed()
            ->create([
                'family_id' => $family->id,
                'email' => '',
            ]);
        $contribution = Contribution::factory()
            ->forUser($member)
            ->currentMonth()
            ->create();

        $this->actingAs($admin)
            ->from('/members/'.$member->id)
            ->post("/contributions/{$contribution->id}/email-reminder")
            ->assertRedirect('/members/'.$member->id)
            ->assertSessionHas('error');

        Notification::assertNothingSent();
    });

    it('redirects back with an error when the contribution is already paid', function () {
        Notification::fake();

        $family = Family::factory()->create();
        $admin = User::factory()->admin()->create(['family_id' => $family->id]);
        $member = User::factory()
            ->member()
            ->employed()
            ->create(['family_id' => $family->id]);
        $contribution = Contribution::factory()
            ->forUser($member)
            ->currentMonth()
            ->create(['expected_amount' => 1000]);

        $contribution->payments()->create([
            'user_id' => $member->id,
            'amount' => 1000,
            'paid_at' => now(),
            'recorded_by' => $admin->id,
        ]);

        $this->actingAs($admin)
            ->from('/members/'.$member->id)
            ->post("/contributions/{$contribution->id}/email-reminder")
            ->assertRedirect('/members/'.$member->id)
            ->assertSessionHas('error');

        Notification::assertNothingSent();
    });
});
