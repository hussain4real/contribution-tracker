<?php

declare(strict_types=1);

use App\Channels\WhatsAppChannel;
use App\Models\Contribution;
use App\Models\Family;
use App\Models\PlatformPlan;
use App\Models\User;
use App\Notifications\ContributionReminderNotification;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use NotificationChannels\WebPush\WebPushChannel;

it('lets a member change reminders for one family only', function () {
    $family = Family::factory()->create();
    $otherFamily = Family::factory()->create();
    $member = User::factory()->member()->employed()->create(['family_id' => $family->id]);
    $member->ensureFamilyMembership($otherFamily);
    $this->actingAs($member)->put(route('notifications.reminder-preferences', ['current_family' => $family->slug]), [
        'channels' => ['mail'],
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect($member->membershipForFamily($family)?->reminder_channels)->toBe(['mail'])
        ->and($member->membershipForFamily($otherFamily)?->reminder_channels)->toBeNull();
    $this->actingAs($member)->get(route('notifications.index', ['current_family' => $family->slug]))
        ->assertOk()->assertInertia(fn (Assert $page) => $page->where('reminderChannels', ['mail']));
});

it('validates preferences and retains in-app reminders when optional channels are disabled', function () {
    $family = Family::factory()->create();
    $member = User::factory()->member()->employed()->create(['family_id' => $family->id]);
    $url = route('notifications.reminder-preferences', ['current_family' => $family->slug]);
    $this->actingAs($member)->put($url, ['channels' => ['unknown']])->assertSessionHasErrors('channels.0');
    $this->actingAs($member)->put($url, ['channels' => []])->assertSessionHasNoErrors();
    $contribution = Contribution::factory()->forUser($member)->currentMonth()->create();
    expect((new ContributionReminderNotification($contribution))->via($member))->toBe(['database']);
});

it('rechecks a member preference when a queued reminder is delivered', function () {
    Mail::fake();
    $family = Family::factory()->create();
    $member = User::factory()->member()->employed()->create(['family_id' => $family->id]);
    $contribution = Contribution::factory()->forUser($member)->currentMonth()->create();
    $notification = new ContributionReminderNotification($contribution);
    expect($notification->via($member))->toBe(['mail', 'database', WhatsAppChannel::class, WebPushChannel::class]);
    $member->membershipForFamily($family)?->update(['reminder_channels' => []]);
    Notification::sendNow($member, $notification, ['mail']);

    Mail::assertNothingOutgoing();
});

it('does not queue a manually requested reminder on a disabled channel', function () {
    Notification::fake();
    $family = Family::factory()->create();
    $officer = User::factory()->financialSecretary()->create(['family_id' => $family->id]);
    $member = User::factory()->member()->employed()->create(['family_id' => $family->id]);
    $member->membershipForFamily($family)?->update(['reminder_channels' => []]);
    $contribution = Contribution::factory()->forUser($member)->currentMonth()->create();
    $this->actingAs($officer)->post(route('contributions.email-reminder', ['current_family' => $family->slug, 'contribution' => $contribution]))
        ->assertRedirect()->assertSessionHas('error', 'This member has disabled email contribution reminders.');
    Notification::assertNothingSent();
});

it('honours the family channel settings as well as the member preferences', function () {
    $family = Family::factory()->create(['reminder_settings' => ['reminder_day' => 10, 'follow_up_day' => 20,
        'time' => '08:00', 'channels' => ['mail'], 'effective_from' => '2020-01']]);
    $member = User::factory()->member()->employed()->create(['family_id' => $family->id]);
    $contribution = Contribution::factory()->forUser($member)->currentMonth()->create();
    expect((new ContributionReminderNotification($contribution))->via($member))->toBe(['mail', 'database']);
    $member->membershipForFamily($family)?->update(['reminder_channels' => ['whatsapp']]);
    expect((new ContributionReminderNotification($contribution))->via($member))->toBe(['database']);
});

it('does not use reminder channels excluded from the family plan', function () {
    $plan = PlatformPlan::create(['name' => 'Test', 'slug' => 'test-plan', 'price' => 0,
        'max_members' => null, 'features' => ['email_reminders'], 'is_active' => true, 'sort_order' => 0]);
    $family = Family::factory()->create(['platform_plan_id' => $plan->id]);
    $member = User::factory()->member()->employed()->create(['family_id' => $family->id]);
    $contribution = Contribution::factory()->forUser($member)->currentMonth()->create();
    expect((new ContributionReminderNotification($contribution))->via($member))->toBe(['mail', 'database']);
});
