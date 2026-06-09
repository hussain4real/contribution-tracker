<?php

declare(strict_types=1);

use App\Channels\WhatsAppChannel;
use App\Models\Contribution;
use App\Models\Family;
use App\Models\Payment;
use App\Models\PlatformPlan;
use App\Models\User;
use App\Notifications\ContributionReminderNotification;
use App\Support\PlatformPlanCatalog;
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
    it('requires whatsapp reminders in the family plan', function () {
        Notification::fake();

        $freePlan = PlatformPlan::create([
            'name' => 'Free',
            'slug' => PlatformPlanCatalog::Free,
            'price' => 0,
            'max_members' => 5,
            'features' => [PlatformPlanCatalog::BasicContributions, PlatformPlanCatalog::ManualPayments],
            'is_active' => true,
            'sort_order' => 0,
        ]);
        $family = Family::factory()->create(['platform_plan_id' => $freePlan->id]);
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
            ->assertRedirect(route('subscription.index'));

        Notification::assertNothingSent();
    });

    it('sends a WhatsApp-only reminder for paid plans that include reminders', function (
        string $name,
        string $slug,
        int $price,
        int $maxMembers,
        int $sortOrder,
    ) {
        Notification::fake();

        $plan = PlatformPlan::create([
            'name' => $name,
            'slug' => $slug,
            'price' => $price,
            'max_members' => $maxMembers,
            'features' => [
                PlatformPlanCatalog::BasicContributions,
                PlatformPlanCatalog::ManualPayments,
                PlatformPlanCatalog::WhatsappReminders,
            ],
            'is_active' => true,
            'sort_order' => $sortOrder,
        ]);
        $family = Family::factory()->create([
            'platform_plan_id' => $plan->id,
            'subscription_status' => 'active',
        ]);
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
    })->with([
        'family plan' => ['Family', PlatformPlanCatalog::Family, 3000, 25, 1],
        'growth plan' => ['Growth', PlatformPlanCatalog::Growth, 7500, 75, 2],
    ]);

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
        Payment::factory()
            ->forContribution($contribution)
            ->recordedBy($admin)
            ->create(['amount' => 1000]);

        $this->actingAs($admin)
            ->from('/members/'.$member->id)
            ->post("/contributions/{$contribution->id}/whatsapp-reminder")
            ->assertRedirect('/members/'.$member->id)
            ->assertSessionHas('error');

        Notification::assertNothingSent();
    });
});
