<?php

declare(strict_types=1);

use App\Channels\WhatsAppChannel;
use App\Jobs\ProcessWhatsAppWebhook;
use App\Models\Contribution;
use App\Models\ContributionReminderDelivery;
use App\Models\Family;
use App\Models\Payment;
use App\Models\User;
use App\Models\WhatsAppMessage;
use App\Notifications\ContributionReminderNotification;
use App\Services\QueueContributionReminder;
use Illuminate\Http\Client\Request;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;

it('tracks queued reminders through mail submission and a WhatsApp delivery callback', function () {
    config(['queue.default' => 'database', 'queue.connections.database.connection' => null, 'mail.default' => 'array',
        'services.whatsapp.access_token' => 'test-token', 'services.whatsapp.phone_number_id' => 'test-id']);
    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.reminder']]])]);
    $family = Family::factory()->create();
    $member = User::factory()->member()->employed()->withVerifiedWhatsApp('+2348012345678')->create(['family_id' => $family->id]);
    $contribution = Contribution::factory()->forUser($member)->currentMonth()->create();
    $notification = (new ContributionReminderNotification($contribution))->onlyChannels(['mail', 'whatsapp']);
    app(QueueContributionReminder::class)->queue($member, $notification);
    expect(ContributionReminderDelivery::where('status', 'queued')->count())->toBe(2);

    $this->artisan('queue:work', ['connection' => 'database', '--once' => true, '--sleep' => 0])->assertSuccessful();
    $this->artisan('queue:work', ['connection' => 'database', '--once' => true, '--sleep' => 0])->assertSuccessful();
    $mail = ContributionReminderDelivery::where('channel', 'mail')->firstOrFail();
    $whatsapp = ContributionReminderDelivery::where('channel', 'whatsapp')->firstOrFail();
    expect($mail->status)->toBe('accepted')->and($whatsapp->status)->toBe('accepted')
        ->and($whatsapp->provider_message_id)->toBe('wamid.reminder')
        ->and($whatsapp->whatsappMessage?->family_id)->toBe($family->id);
    (new ProcessWhatsAppWebhook(['entry' => [['changes' => [[
        'field' => 'messages', 'value' => ['statuses' => [['id' => 'wamid.reminder', 'status' => 'delivered']]],
    ]]]]]))->handle();
    expect($whatsapp->fresh()?->displayStatus())->toBe('delivered');
});

it('marks a queued reminder unavailable if the member pays before the worker sends it', function () {
    config(['queue.default' => 'database', 'queue.connections.database.connection' => null]);
    $family = Family::factory()->create();
    $member = User::factory()->member()->employed()->create(['family_id' => $family->id]);
    $contribution = Contribution::factory()->forUser($member)->currentMonth()->create(['expected_amount' => 1000]);
    app(QueueContributionReminder::class)->queue($member, (new ContributionReminderNotification($contribution))->onlyChannels(['mail']));
    Payment::factory()->forContribution($contribution)->create(['amount' => 1000]);
    $this->artisan('queue:work', ['connection' => 'database', '--once' => true, '--sleep' => 0])->assertSuccessful();
    expect(ContributionReminderDelivery::sole()->status)->toBe('unavailable');
});

it('sends the updated balance when a partial payment arrives while a reminder is queued', function () {
    config(['queue.default' => 'database', 'queue.connections.database.connection' => null,
        'services.whatsapp.access_token' => 'test-token', 'services.whatsapp.phone_number_id' => 'test-id']);
    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.partial']]])]);
    $family = Family::factory()->create(['currency' => 'NGN']);
    $member = User::factory()->member()->employed()->withVerifiedWhatsApp('+2348012345678')->create(['family_id' => $family->id]);
    $contribution = Contribution::factory()->forUser($member)->currentMonth()->create(['expected_amount' => 4000]);
    app(QueueContributionReminder::class)->queue($member, (new ContributionReminderNotification($contribution))->onlyChannels(['whatsapp']));
    Payment::factory()->forContribution($contribution)->create(['amount' => 1500]);

    $this->artisan('queue:work', ['connection' => 'database', '--once' => true, '--sleep' => 0])->assertSuccessful();

    Http::assertSent(fn (Request $request): bool => data_get($request->data(), 'template.components.0.parameters.4.text') === 'NGN 2,500.00');
    expect(ContributionReminderDelivery::sole()->status)->toBe('accepted');
});

it('suppresses a queued reminder when the member disables that channel', function () {
    config(['queue.default' => 'database', 'queue.connections.database.connection' => null,
        'services.whatsapp.access_token' => 'test-token', 'services.whatsapp.phone_number_id' => 'test-id']);
    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.disabled']]])]);
    $family = Family::factory()->create();
    $member = User::factory()->member()->employed()->withVerifiedWhatsApp('+2348012345678')->create(['family_id' => $family->id]);
    $contribution = Contribution::factory()->forUser($member)->currentMonth()->create();
    app(QueueContributionReminder::class)->queue($member, (new ContributionReminderNotification($contribution))->onlyChannels(['whatsapp']));
    $member->membershipForFamily($family)?->update(['reminder_channels' => []]);

    $this->artisan('queue:work', ['connection' => 'database', '--once' => true, '--sleep' => 0])->assertSuccessful();

    Http::assertNothingSent();
    expect(ContributionReminderDelivery::sole()->status)->toBe('unavailable');
});

it('does not send an accepted reminder again if its job is replayed', function () {
    config(['queue.default' => 'database', 'queue.connections.database.connection' => null,
        'services.whatsapp.access_token' => 'test-token', 'services.whatsapp.phone_number_id' => 'test-id']);
    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.once']]])]);
    $family = Family::factory()->create();
    $member = User::factory()->member()->employed()->withVerifiedWhatsApp('+2348012345678')->create(['family_id' => $family->id]);
    $contribution = Contribution::factory()->forUser($member)->currentMonth()->create();
    app(QueueContributionReminder::class)->queue($member, (new ContributionReminderNotification($contribution))->onlyChannels(['whatsapp']));
    $queuedJob = (array) DB::table('jobs')->first();
    unset($queuedJob['id']);

    $this->artisan('queue:work', ['connection' => 'database', '--once' => true, '--sleep' => 0])->assertSuccessful();
    DB::table('jobs')->insert($queuedJob);
    $this->artisan('queue:work', ['connection' => 'database', '--once' => true, '--sleep' => 0])->assertSuccessful();

    Http::assertSentCount(1);
    expect(ContributionReminderDelivery::sole()->status)->toBe('accepted');
});

it('does not send a reminder twice when queued executions overlap', function () {
    config(['queue.default' => 'database', 'queue.connections.database.connection' => null,
        'services.whatsapp.access_token' => 'test-token', 'services.whatsapp.phone_number_id' => 'test-id']);
    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.overlap']]])]);
    $family = Family::factory()->create();
    $member = User::factory()->member()->employed()->withVerifiedWhatsApp('+2348012345678')->create(['family_id' => $family->id]);
    $contribution = Contribution::factory()->forUser($member)->currentMonth()->create();
    app(QueueContributionReminder::class)->queue($member, (new ContributionReminderNotification($contribution))->onlyChannels(['whatsapp']));
    $queuedJob = (array) DB::table('jobs')->first();
    unset($queuedJob['id']);
    DB::table('jobs')->insert($queuedJob);
    $overlapped = false;
    Event::listen(NotificationSending::class, function () use (&$overlapped): void {
        if (! $overlapped) {
            $overlapped = true;
            $this->artisan('queue:work', ['connection' => 'database', '--once' => true, '--sleep' => 0])->assertSuccessful();
        }
    });

    $this->artisan('queue:work', ['connection' => 'database', '--once' => true, '--sleep' => 0])->assertSuccessful();

    expect($overlapped)->toBeTrue();
    Http::assertSentCount(1);
    expect(ContributionReminderDelivery::sole()->status)->toBe('accepted');
    expect(DB::table('jobs')->count())->toBe(1);
    $this->travel(91)->seconds();
    $this->artisan('queue:work', ['connection' => 'database', '--once' => true, '--sleep' => 0])->assertSuccessful();
    Http::assertSentCount(1);
    expect(DB::table('jobs')->count())->toBe(0);
});

it('records an unconfirmed WhatsApp attempt when the provider request fails', function () {
    config(['queue.default' => 'database', 'queue.connections.database.connection' => null,
        'services.whatsapp.access_token' => 'test-token', 'services.whatsapp.phone_number_id' => 'test-id']);
    Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'sensitive provider detail']], 503)]);
    $family = Family::factory()->create();
    $member = User::factory()->member()->employed()->withVerifiedWhatsApp('+2348012345678')->create(['family_id' => $family->id]);
    $contribution = Contribution::factory()->forUser($member)->currentMonth()->create();
    app(QueueContributionReminder::class)->queue($member, (new ContributionReminderNotification($contribution))->onlyChannels(['whatsapp']));
    $this->artisan('queue:work', ['connection' => 'database', '--once' => true, '--sleep' => 0])->assertSuccessful();
    $delivery = ContributionReminderDelivery::sole();
    expect($delivery->displayStatus())->toBe('unconfirmed')
        ->and($delivery->failureReason())->not->toContain('sensitive provider detail');
});

it('marks the correct channel unconfirmed when a queued notification exhausts retries', function () {
    $family = Family::factory()->create();
    $member = User::factory()->member()->employed()->create(['family_id' => $family->id]);
    $contribution = Contribution::factory()->forUser($member)->currentMonth()->create();
    $mail = ContributionReminderDelivery::factory()->create(['contribution_id' => $contribution->id, 'channel' => 'mail']);
    $whatsapp = ContributionReminderDelivery::factory()->create(['contribution_id' => $contribution->id, 'channel' => 'whatsapp']);
    $notification = (new ContributionReminderNotification($contribution))->trackDeliveries(['mail' => $mail->id, WhatsAppChannel::class => $whatsapp->id]);
    $notification->middleware($member, 'mail');
    (new SendQueuedNotifications(collect([$member]), $notification, ['mail']))->failed(new RuntimeException('Transport unavailable'));
    expect($mail->fresh()?->displayStatus())->toBe('unconfirmed')->and($whatsapp->fresh()?->status)->toBe('queued');
});

it('marks a reminder unconfirmed after the queue exhausts delivery exceptions', function () {
    config(['queue.default' => 'database', 'queue.connections.database.connection' => null]);
    $family = Family::factory()->create();
    $member = User::factory()->member()->employed()->create(['family_id' => $family->id]);
    $contribution = Contribution::factory()->forUser($member)->currentMonth()->create();
    app(QueueContributionReminder::class)->queue($member, (new ContributionReminderNotification($contribution))->onlyChannels(['mail']));
    Event::listen(NotificationSending::class, function (): never {
        throw new RuntimeException('Synthetic transport failure');
    });

    for ($attempt = 0; $attempt < 3; $attempt++) {
        $this->artisan('queue:work', ['connection' => 'database', '--once' => true, '--sleep' => 0])->assertSuccessful();
        $this->travel(20)->seconds();
    }

    expect(ContributionReminderDelivery::sole()->displayStatus())->toBe('unconfirmed');
    expect(DB::table('jobs')->count())->toBe(0);
    expect(DB::table('failed_jobs')->count())->toBe(1);
});

it('shows officers reminder failures and lets them retry once without altering balances', function () {
    Notification::fake();
    $family = Family::factory()->create();
    $member = User::factory()->member()->employed()->create(['family_id' => $family->id]);
    $officer = User::factory()->financialSecretary()->create(['family_id' => $family->id]);
    $contribution = Contribution::factory()->forUser($member)->currentMonth()->create(['expected_amount' => 1000]);
    $delivery = ContributionReminderDelivery::factory()->create(['contribution_id' => $contribution->id, 'channel' => 'mail', 'status' => 'failed']);
    $this->actingAs($officer)->get(route('contributions.show', ['current_family' => $family->slug, 'contribution' => $contribution]))
        ->assertOk()->assertInertia(fn (Assert $page) => $page->where('reminder_deliveries.0.status', 'unconfirmed')
        ->where('reminder_deliveries.0.can_retry', true));
    $url = route('reminder-deliveries.retry', ['current_family' => $family->slug, 'delivery' => $delivery->id]);
    $this->actingAs($member)->post($url)->assertForbidden();
    $otherFamily = Family::factory()->create();
    $otherAdmin = User::factory()->admin()->create(['family_id' => $otherFamily->id]);
    $this->actingAs($otherAdmin)->post(route('reminder-deliveries.retry', ['current_family' => $otherFamily->slug, 'delivery' => $delivery->id]))->assertNotFound();
    $this->actingAs($officer)->post($url)->assertRedirect()->assertSessionHas('success');
    $this->actingAs($officer)->post($url)->assertRedirect()->assertSessionHas('error');
    expect($delivery->fresh()?->status)->toBe('queued')->and($contribution->fresh()?->balance)->toBe(1000);
    Notification::assertSentToTimes($member, ContributionReminderNotification::class, 1);
});

it('rejects retries when the member has disabled the reminder channel', function () {
    Notification::fake();
    $family = Family::factory()->create();
    $member = User::factory()->member()->employed()->create(['family_id' => $family->id]);
    $officer = User::factory()->financialSecretary()->create(['family_id' => $family->id]);
    $member->membershipForFamily($family)?->update(['reminder_channels' => []]);
    $contribution = Contribution::factory()->forUser($member)->currentMonth()->create();
    $delivery = ContributionReminderDelivery::factory()->create(['contribution_id' => $contribution->id, 'channel' => 'mail', 'status' => 'failed']);
    $this->actingAs($officer)->get(route('contributions.show', ['current_family' => $family->slug, 'contribution' => $contribution]))
        ->assertOk()->assertInertia(fn (Assert $page) => $page->where('reminder_deliveries.0.can_retry', false));
    $this->actingAs($officer)->post(route('reminder-deliveries.retry', ['current_family' => $family->slug, 'delivery' => $delivery->id]))
        ->assertRedirect()->assertSessionHas('error');
    expect($delivery->fresh()?->status)->toBe('failed');
    Notification::assertNothingSent();
});

it('does not offer a reminder retry when the family disables the channel or the reminder type is unsupported', function (string $reason) {
    Notification::fake();
    $family = Family::factory()->create();
    if ($reason === 'family channel') {
        $family->update(['reminder_settings' => ['reminder_day' => 10, 'follow_up_day' => 20,
            'time' => '08:00', 'channels' => [], 'effective_from' => '2020-01']]);
    }
    $member = User::factory()->member()->employed()->create(['family_id' => $family->id]);
    $officer = User::factory()->financialSecretary()->create(['family_id' => $family->id]);
    $contribution = Contribution::factory()->forUser($member)->currentMonth()->create();
    $delivery = ContributionReminderDelivery::factory()->create([
        'contribution_id' => $contribution->id, 'channel' => 'mail', 'status' => 'failed',
        'reminder_type' => $reason === 'unsupported type' ? 'unsupported' : 'reminder',
    ]);

    $this->actingAs($officer)->get(route('contributions.show', ['current_family' => $family->slug, 'contribution' => $contribution]))
        ->assertOk()->assertInertia(fn (Assert $page) => $page->where('reminder_deliveries.0.can_retry', false));
    $this->post(route('reminder-deliveries.retry', ['current_family' => $family->slug, 'delivery' => $delivery->id]))
        ->assertRedirect()->assertSessionHas('error');
    Notification::assertNothingSent();
})->with(['family channel', 'unsupported type']);

it('keeps contribution details available when stored reminder settings are invalid', function () {
    $family = Family::factory()->create(['reminder_settings' => ['reminder_day' => 29, 'follow_up_day' => 28,
        'time' => '08:00', 'channels' => ['mail'], 'effective_from' => '2020-01']]);
    $member = User::factory()->member()->employed()->create(['family_id' => $family->id]);
    $officer = User::factory()->financialSecretary()->create(['family_id' => $family->id]);
    $contribution = Contribution::factory()->forUser($member)->currentMonth()->create();
    ContributionReminderDelivery::factory()->create(['contribution_id' => $contribution->id, 'channel' => 'mail', 'status' => 'failed']);

    $this->actingAs($officer)->get(route('contributions.show', ['current_family' => $family->slug, 'contribution' => $contribution]))
        ->assertOk()->assertInertia(fn (Assert $page) => $page->where('reminder_deliveries.0.can_retry', false));
});

it('shows a provider-reported WhatsApp failure and retries only that reminder', function () {
    Notification::fake();
    $family = Family::factory()->create();
    $member = User::factory()->member()->employed()->withVerifiedWhatsApp('+2348012345678')->create(['family_id' => $family->id]);
    $officer = User::factory()->financialSecretary()->create(['family_id' => $family->id]);
    $contribution = Contribution::factory()->forUser($member)->currentMonth()->create();
    WhatsAppMessage::factory()->create(['wa_message_id' => 'wamid.reminder-failed', 'status' => 'failed',
        'error_code' => '131000', 'error_message' => 'private provider detail']);
    $delivery = ContributionReminderDelivery::factory()->create(['contribution_id' => $contribution->id,
        'channel' => 'whatsapp', 'status' => 'accepted', 'provider_message_id' => 'wamid.reminder-failed']);

    $response = $this->actingAs($officer)->get(route('contributions.show', ['current_family' => $family->slug, 'contribution' => $contribution]));
    $response->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('reminder_deliveries.0.status', 'failed')
        ->where('reminder_deliveries.0.failure_reason', 'WhatsApp reported delivery error 131000.')
        ->where('reminder_deliveries.0.can_retry', true));
    $response->assertDontSee('private provider detail');
    $this->actingAs($officer)->post(route('reminder-deliveries.retry', ['current_family' => $family->slug, 'delivery' => $delivery->id]))
        ->assertRedirect()->assertSessionHas('success');
    expect($delivery->fresh()?->status)->toBe('queued')->and($delivery->fresh()?->provider_message_id)->toBeNull();
    Notification::assertSentToTimes($member, ContributionReminderNotification::class, 1);
});

it('does not expose unsafe provider failure details in reminder history', function () {
    $family = Family::factory()->create();
    $member = User::factory()->member()->employed()->create(['family_id' => $family->id]);
    $officer = User::factory()->financialSecretary()->create(['family_id' => $family->id]);
    $contribution = Contribution::factory()->forUser($member)->currentMonth()->create();
    WhatsAppMessage::factory()->create(['wa_message_id' => 'wamid.unsafe', 'status' => 'failed',
        'error_code' => 'secret-code', 'error_message' => 'private provider detail']);
    ContributionReminderDelivery::factory()->create(['contribution_id' => $contribution->id,
        'channel' => 'whatsapp', 'status' => 'accepted', 'provider_message_id' => 'wamid.unsafe']);

    $response = $this->actingAs($officer)->get(route('contributions.show', ['current_family' => $family->slug, 'contribution' => $contribution]));
    $response->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('reminder_deliveries.0.failure_reason', 'WhatsApp reported that delivery failed.'));
    $response->assertDontSee('secret-code')->assertDontSee('private provider detail');
});

it('shows a queued reminder without a failure reason', function () {
    $family = Family::factory()->create();
    $member = User::factory()->member()->employed()->create(['family_id' => $family->id]);
    $officer = User::factory()->financialSecretary()->create(['family_id' => $family->id]);
    $contribution = Contribution::factory()->forUser($member)->currentMonth()->create();
    ContributionReminderDelivery::factory()->create(['contribution_id' => $contribution->id, 'channel' => 'mail', 'status' => 'queued']);

    $this->actingAs($officer)->get(route('contributions.show', ['current_family' => $family->slug, 'contribution' => $contribution]))
        ->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('reminder_deliveries.0.status', 'queued')
        ->where('reminder_deliveries.0.failure_reason', null)
        ->where('reminder_deliveries.0.can_retry', false));
});

it('queues a browser reminder retry without changing the contribution', function () {
    Notification::fake();
    $family = Family::factory()->create();
    $member = User::factory()->member()->employed()->create(['family_id' => $family->id]);
    $officer = User::factory()->financialSecretary()->create(['family_id' => $family->id]);
    $contribution = Contribution::factory()->forUser($member)->currentMonth()->create(['expected_amount' => 1000]);
    $delivery = ContributionReminderDelivery::factory()->create(['contribution_id' => $contribution->id, 'channel' => 'webpush', 'status' => 'failed']);
    $this->actingAs($officer)->post(route('reminder-deliveries.retry', ['current_family' => $family->slug, 'delivery' => $delivery->id]))
        ->assertRedirect()->assertSessionHas('success');
    expect($delivery->fresh()?->status)->toBe('queued')->and($contribution->fresh()?->balance)->toBe(1000);
});

it('does not queue a reminder retry if its delivery status changes concurrently', function () {
    Notification::fake();
    $family = Family::factory()->create();
    $member = User::factory()->member()->employed()->create(['family_id' => $family->id]);
    $officer = User::factory()->financialSecretary()->create(['family_id' => $family->id]);
    $contribution = Contribution::factory()->forUser($member)->currentMonth()->create();
    $delivery = ContributionReminderDelivery::factory()->create(['contribution_id' => $contribution->id, 'channel' => 'mail', 'status' => 'failed']);
    DB::statement("create trigger simulate_reminder_status_race before update on contribution_reminder_deliveries when old.status = 'failed' and new.status = 'queued' begin update contribution_reminder_deliveries set status='accepted' where id=old.id; select raise(ignore); end");

    $this->actingAs($officer)->post(route('reminder-deliveries.retry', ['current_family' => $family->slug, 'delivery' => $delivery->id]))
        ->assertRedirect()->assertSessionHas('error', 'Delivery status changed. Refresh and check it before retrying.');
    expect($delivery->fresh()?->status)->toBe('accepted');
    Notification::assertNothingSent();
});

it('marks a browser reminder unavailable when the member has no subscription', function () {
    config(['queue.default' => 'database', 'queue.connections.database.connection' => null]);
    $family = Family::factory()->create();
    $member = User::factory()->member()->employed()->create(['family_id' => $family->id]);
    $contribution = Contribution::factory()->forUser($member)->currentMonth()->create();
    app(QueueContributionReminder::class)->queue($member, (new ContributionReminderNotification($contribution))->onlyChannels(['webpush']));

    $this->artisan('queue:work', ['connection' => 'database', '--once' => true, '--sleep' => 0])->assertSuccessful();
    expect(ContributionReminderDelivery::sole()->status)->toBe('unavailable');
});
