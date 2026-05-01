<?php

use App\Channels\WhatsAppChannel;
use App\Channels\WhatsAppMessage;
use App\Models\Contribution;
use App\Models\Family;
use App\Models\Payment;
use App\Models\User;
use App\Notifications\ContributionReminderNotification;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Queue\Middleware\RateLimited;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

describe('ContributionReminderNotification', function () {
    it('sends via mail, database, whatsapp, and web push channels', function () {
        $family = Family::factory()->create();
        $user = User::factory()->member()->employed()->create(['family_id' => $family->id]);
        $contribution = Contribution::factory()->forUser($user)->currentMonth()->create();

        $notification = new ContributionReminderNotification($contribution, 'reminder');

        expect($notification->via($user))->toBe(['mail', 'database', WhatsAppChannel::class, WebPushChannel::class]);
    });

    it('respects channel restrictions when web push is not requested', function (string $channel, string $expected) {
        $family = Family::factory()->create();
        $user = User::factory()->member()->employed()->create(['family_id' => $family->id]);
        $contribution = Contribution::factory()->forUser($user)->currentMonth()->create();

        $notification = (new ContributionReminderNotification($contribution, 'reminder'))
            ->onlyChannels([$channel]);

        expect($notification->via($user))->toBe([$expected]);
    })->with([
        'mail' => ['mail', 'mail'],
        'database' => ['database', 'database'],
        'whatsapp' => ['whatsapp', WhatsAppChannel::class],
    ]);

    it('allows restricting delivery to web push only', function () {
        $family = Family::factory()->create();
        $user = User::factory()->member()->employed()->create(['family_id' => $family->id]);
        $contribution = Contribution::factory()->forUser($user)->currentMonth()->create();

        $notification = (new ContributionReminderNotification($contribution, 'reminder'))
            ->onlyChannels(['webpush']);

        expect($notification->via($user))->toBe([WebPushChannel::class]);
    });

    it('rate limits whatsapp delivery and retries with backoff', function () {
        $family = Family::factory()->create();
        $user = User::factory()->member()->employed()->create(['family_id' => $family->id]);
        $contribution = Contribution::factory()->forUser($user)->currentMonth()->create();

        $notification = new ContributionReminderNotification($contribution, 'reminder');

        expect($notification->middleware($user, 'mail'))->toBe([])
            ->and($notification->middleware($user, WhatsAppChannel::class)[0])->toBeInstanceOf(RateLimited::class)
            ->and($notification->backoff())->toBe([1, 5, 10]);
    });

    it('builds correct mail content for reminder type', function () {
        $family = Family::factory()->create(['name' => 'Test Family']);
        $user = User::factory()->member()->employed()->create(['family_id' => $family->id]);
        $contribution = Contribution::factory()->forUser($user)->currentMonth()->create();

        $notification = new ContributionReminderNotification($contribution, 'reminder');
        $mail = $notification->toMail($user);

        expect($mail)->toBeInstanceOf(MailMessage::class)
            ->and($mail->subject)->toContain('Reminder')
            ->and($mail->subject)->toContain($contribution->period_label);
    });

    it('builds correct mail content for follow_up type', function () {
        $family = Family::factory()->create(['name' => 'Test Family']);
        $user = User::factory()->member()->employed()->create(['family_id' => $family->id]);
        $contribution = Contribution::factory()->forUser($user)->currentMonth()->create();

        $notification = new ContributionReminderNotification($contribution, 'follow_up');
        $mail = $notification->toMail($user);

        expect($mail->subject)->toContain('Follow-up')
            ->and($mail->subject)->toContain($contribution->period_label);
    });

    it('returns correct database notification data', function () {
        $family = Family::factory()->create(['name' => 'Test Family']);
        $user = User::factory()->member()->employed()->create(['family_id' => $family->id]);
        $contribution = Contribution::factory()->forUser($user)->currentMonth()->create();

        $notification = new ContributionReminderNotification($contribution, 'reminder');
        $data = $notification->toArray($user);

        expect($data)->toHaveKeys([
            'contribution_id',
            'family_name',
            'period_label',
            'amount_owed',
            'due_date',
            'type',
        ])
            ->and($data['contribution_id'])->toBe($contribution->id)
            ->and($data['family_name'])->toBe('Test Family')
            ->and($data['period_label'])->toBe($contribution->period_label)
            ->and($data['type'])->toBe('reminder');
    });

    it('includes correct balance as amount owed', function () {
        $family = Family::factory()->create();
        $user = User::factory()->member()->employed()->create(['family_id' => $family->id]);
        $contribution = Contribution::factory()->forUser($user)->currentMonth()->create([
            'expected_amount' => 4000,
        ]);

        Payment::factory()->create([
            'contribution_id' => $contribution->id,
            'amount' => 1000,
            'paid_at' => now(),
        ]);

        $contribution->refresh();

        $notification = new ContributionReminderNotification($contribution, 'reminder');
        $data = $notification->toArray($user);

        expect($data['amount_owed'])->toBe(3000);
    });

    it('stores follow_up type in database data', function () {
        $family = Family::factory()->create();
        $user = User::factory()->member()->employed()->create(['family_id' => $family->id]);
        $contribution = Contribution::factory()->forUser($user)->currentMonth()->create();

        $notification = new ContributionReminderNotification($contribution, 'follow_up');
        $data = $notification->toArray($user);

        expect($data['type'])->toBe('follow_up');
    });

    it('builds whatsapp template message with 5 body params', function () {
        $family = Family::factory()->create(['name' => 'Smith Family']);
        $user = User::factory()->member()->employed()->create([
            'name' => 'Jane Doe',
            'family_id' => $family->id,
        ]);
        $contribution = Contribution::factory()->forUser($user)->currentMonth()->create([
            'expected_amount' => 5000,
        ]);

        $notification = new ContributionReminderNotification($contribution, 'reminder');
        $payload = $notification->toWhatsApp($user)->toPayload('2348012345678');

        expect($payload['type'])->toBe('template')
            ->and($payload['template']['name'])->toBe('contribution_reminder')
            ->and($payload['template']['language']['code'])->toBe('en')
            ->and($payload['template']['components'][0]['type'])->toBe('body')
            ->and($payload['template']['components'][0]['parameters'])->toHaveCount(5);
    });

    it('uses follow-up wording in whatsapp template for follow_up type', function () {
        $family = Family::factory()->create();
        $user = User::factory()->member()->employed()->create(['family_id' => $family->id]);
        $contribution = Contribution::factory()->forUser($user)->currentMonth()->create();

        $notification = new ContributionReminderNotification($contribution, 'follow_up');
        $message = $notification->toWhatsApp($user);

        expect($message)->toBeInstanceOf(WhatsAppMessage::class);

        $params = $message->toPayload('2348012345678')['template']['components'][0]['parameters'];

        expect($params[1]['text'])->toBe('follow-up');
    });

    it('builds a web push message for reminder type', function () {
        $family = Family::factory()->create(['name' => 'Smith Family']);
        $user = User::factory()->member()->employed()->create(['family_id' => $family->id]);
        $contribution = Contribution::factory()->forUser($user)->currentMonth()->create([
            'expected_amount' => 5000,
        ]);

        $notification = new ContributionReminderNotification($contribution, 'reminder');
        $message = $notification->toWebPush($user, $notification);
        $payload = $message->toArray();

        expect($message)->toBeInstanceOf(WebPushMessage::class)
            ->and($payload['title'])->toBe('Contribution due soon')
            ->and($payload['body'])->toContain($contribution->period_label)
            ->and($payload['body'])->toContain('Smith Family')
            ->and($payload['icon'])->toBe('/pwa-192x192.png')
            ->and($payload['badge'])->toBe('/pwa-192x192.png')
            ->and($payload['tag'])->toBe("contribution-{$contribution->id}-reminder")
            ->and($payload['data']['contribution_id'])->toBe($contribution->id)
            ->and($payload['data']['url'])->toBe(route('contributions.show', $contribution))
            ->and($payload['data']['type'])->toBe('reminder')
            ->and($message->getOptions())->toBe(['TTL' => 60 * 60 * 24]);
    });

    it('builds a web push message for follow_up type', function () {
        $family = Family::factory()->create();
        $user = User::factory()->member()->employed()->create(['family_id' => $family->id]);
        $contribution = Contribution::factory()->forUser($user)->currentMonth()->create();

        $notification = new ContributionReminderNotification($contribution, 'follow_up');
        $payload = $notification->toWebPush($user, $notification)->toArray();

        expect($payload['title'])->toBe('Contribution due today')
            ->and($payload['tag'])->toBe("contribution-{$contribution->id}-follow_up")
            ->and($payload['data']['type'])->toBe('follow_up');
    });
});
