<?php

use App\Channels\WhatsAppChannel;
use App\Models\Contribution;
use App\Models\Family;
use App\Models\User;
use App\Notifications\ContributionReminderNotification;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    config()->set('services.whatsapp', [
        'access_token' => 'test-token',
        'phone_number_id' => '1038448572690931',
        'business_account_id' => '965423126197935',
        'api_version' => 'v25.0',
        'base_url' => 'https://graph.facebook.com',
        'webhook_verify_token' => 'verify',
        'app_secret' => 'secret',
    ]);
});

describe('Manual WhatsApp contribution reminder', function () {
    it('sends a WhatsApp-only reminder when admin triggers it', function () {
        Notification::fake();

        $family = Family::factory()->create();
        $admin = User::factory()->admin()->create(['family_id' => $family->id]);
        $member = User::factory()
            ->member()
            ->employed()
            ->withVerifiedWhatsApp('+15551234567')
            ->create(['family_id' => $family->id]);
        $contribution = Contribution::factory()
            ->forUser($member)
            ->currentMonth()
            ->create(['expected_amount' => 5000]);

        $this->actingAs($admin)
            ->post("/contributions/{$contribution->id}/whatsapp-reminder")
            ->assertRedirect();

        Notification::assertSentTo(
            $member,
            ContributionReminderNotification::class,
            function (ContributionReminderNotification $notification) use ($member): bool {
                return $notification->via($member) === [WhatsAppChannel::class];
            },
        );
    });

    it('allows financial secretary to send a reminder', function () {
        Notification::fake();

        $family = Family::factory()->create();
        $fs = User::factory()->financialSecretary()->create(['family_id' => $family->id]);
        $member = User::factory()
            ->member()
            ->employed()
            ->withVerifiedWhatsApp()
            ->create(['family_id' => $family->id]);
        $contribution = Contribution::factory()
            ->forUser($member)
            ->currentMonth()
            ->create(['expected_amount' => 5000]);

        $this->actingAs($fs)
            ->post("/contributions/{$contribution->id}/whatsapp-reminder")
            ->assertRedirect();

        Notification::assertSentTo($member, ContributionReminderNotification::class);
    });

    it('forbids regular members from sending a reminder', function () {
        Notification::fake();

        $family = Family::factory()->create();
        $sender = User::factory()->member()->employed()->create(['family_id' => $family->id]);
        $target = User::factory()
            ->member()
            ->employed()
            ->withVerifiedWhatsApp()
            ->create(['family_id' => $family->id]);
        $contribution = Contribution::factory()
            ->forUser($target)
            ->currentMonth()
            ->create();

        $this->actingAs($sender)
            ->post("/contributions/{$contribution->id}/whatsapp-reminder")
            ->assertForbidden();

        Notification::assertNothingSent();
    });

    it('forbids admins from sending reminders for contributions outside their family', function () {
        Notification::fake();

        $familyA = Family::factory()->create();
        $familyB = Family::factory()->create();
        $admin = User::factory()->admin()->create(['family_id' => $familyA->id]);
        $member = User::factory()
            ->member()
            ->employed()
            ->withVerifiedWhatsApp()
            ->create(['family_id' => $familyB->id]);
        $contribution = Contribution::factory()
            ->forUser($member)
            ->currentMonth()
            ->create();

        $this->actingAs($admin)
            ->post("/contributions/{$contribution->id}/whatsapp-reminder")
            ->assertForbidden();

        Notification::assertNothingSent();
    });

    it('redirects back with an error when the member has no verified WhatsApp', function () {
        Notification::fake();

        $family = Family::factory()->create();
        $admin = User::factory()->admin()->create(['family_id' => $family->id]);
        $member = User::factory()
            ->member()
            ->employed()
            ->withoutWhatsApp()
            ->create(['family_id' => $family->id]);
        $contribution = Contribution::factory()
            ->forUser($member)
            ->currentMonth()
            ->create();

        $this->actingAs($admin)
            ->from('/members/'.$member->id)
            ->post("/contributions/{$contribution->id}/whatsapp-reminder")
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
            ->withVerifiedWhatsApp()
            ->create(['family_id' => $family->id]);
        $contribution = Contribution::factory()
            ->forUser($member)
            ->currentMonth()
            ->create(['expected_amount' => 1000]);

        // Fully pay it.
        $contribution->payments()->create([
            'user_id' => $member->id,
            'amount' => 1000,
            'paid_at' => now(),
            'recorded_by' => $admin->id,
        ]);

        $this->actingAs($admin)
            ->from('/members/'.$member->id)
            ->post("/contributions/{$contribution->id}/whatsapp-reminder")
            ->assertRedirect('/members/'.$member->id)
            ->assertSessionHas('error');

        Notification::assertNothingSent();
    });
});
