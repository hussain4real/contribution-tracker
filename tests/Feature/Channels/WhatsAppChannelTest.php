<?php

use App\Channels\WhatsAppChannel;
use App\Channels\WhatsAppMessage;
use App\Services\WhatsAppService;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;

it('sends valid whatsapp notification messages to the normalised recipient', function () {
    $message = (new WhatsAppMessage)->text('Contribution reminder');
    $notification = new class($message) extends Notification
    {
        public function __construct(private readonly WhatsAppMessage $message) {}

        public function toWhatsApp(object $notifiable): WhatsAppMessage
        {
            return $this->message;
        }
    };
    $notifiable = new class
    {
        public function routeNotificationFor(string $driver, Notification $notification): string
        {
            expect($driver)->toBe('whatsapp');

            return '+234 801 234 5678';
        }
    };

    $whatsapp = Mockery::mock(WhatsAppService::class);
    $whatsapp->shouldReceive('normalisePhone')
        ->once()
        ->with('+234 801 234 5678')
        ->andReturn('2348012345678');
    $whatsapp->shouldReceive('send')
        ->once()
        ->with('2348012345678', $message)
        ->andReturn(['success' => true, 'wa_message_id' => 'wamid.123', 'error' => null]);

    (new WhatsAppChannel($whatsapp))->send($notifiable, $notification);
});

it('skips delivery when the notifiable has no whatsapp route', function () {
    $notification = new class extends Notification
    {
        public function toWhatsApp(object $notifiable): WhatsAppMessage
        {
            return (new WhatsAppMessage)->text('Contribution reminder');
        }
    };
    $notifiable = new class
    {
        public function routeNotificationFor(string $driver, Notification $notification): ?string
        {
            return null;
        }
    };

    $whatsapp = Mockery::mock(WhatsAppService::class);
    $whatsapp->shouldNotReceive('normalisePhone');
    $whatsapp->shouldNotReceive('send');

    (new WhatsAppChannel($whatsapp))->send($notifiable, $notification);
});

it('skips delivery when the notification does not provide a whatsapp message', function () {
    $notification = new class extends Notification {};
    $notifiable = new class
    {
        public function routeNotificationFor(string $driver, Notification $notification): string
        {
            return '+234 801 234 5678';
        }
    };

    $whatsapp = Mockery::mock(WhatsAppService::class);
    $whatsapp->shouldNotReceive('normalisePhone');
    $whatsapp->shouldNotReceive('send');

    (new WhatsAppChannel($whatsapp))->send($notifiable, $notification);
});

it('logs and skips delivery when the whatsapp message cannot be built', function () {
    Log::shouldReceive('error')
        ->once()
        ->with('Failed to build WhatsApp notification message', Mockery::on(
            fn (array $context): bool => $context['error'] === 'Template unavailable',
        ));

    $notification = new class extends Notification
    {
        public function toWhatsApp(object $notifiable): WhatsAppMessage
        {
            throw new RuntimeException('Template unavailable');
        }
    };
    $notifiable = new class
    {
        public function routeNotificationFor(string $driver, Notification $notification): string
        {
            return '+234 801 234 5678';
        }
    };

    $whatsapp = Mockery::mock(WhatsAppService::class);
    $whatsapp->shouldNotReceive('normalisePhone');
    $whatsapp->shouldNotReceive('send');

    (new WhatsAppChannel($whatsapp))->send($notifiable, $notification);
});

it('logs and skips delivery when toWhatsapp returns an invalid message', function () {
    Log::shouldReceive('warning')
        ->once()
        ->with('toWhatsApp() must return a WhatsAppMessage instance', Mockery::on(
            fn (array $context): bool => str_contains($context['notification'], 'Notification'),
        ));

    $notification = new class extends Notification
    {
        public function toWhatsApp(object $notifiable): string
        {
            return 'invalid';
        }
    };
    $notifiable = new class
    {
        public function routeNotificationFor(string $driver, Notification $notification): string
        {
            return '+234 801 234 5678';
        }
    };

    $whatsapp = Mockery::mock(WhatsAppService::class);
    $whatsapp->shouldNotReceive('normalisePhone');
    $whatsapp->shouldNotReceive('send');

    (new WhatsAppChannel($whatsapp))->send($notifiable, $notification);
});
