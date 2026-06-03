<?php

declare(strict_types=1);

use App\Channels\WhatsAppMessage;
use App\Models\WhatsAppMessage as WhatsAppMessageModel;
use App\Services\WhatsAppService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

beforeEach(function () {
    config()->set('services.whatsapp', [
        'access_token' => 'test-token',
        'phone_number_id' => '1038448572690931',
        'business_account_id' => '965423126197935',
        'api_version' => 'v25.0',
        'base_url' => 'https://graph.facebook.com',
        'webhook_verify_token' => 'verify-token-abc',
        'app_secret' => 'app-secret-xyz',
        'templates' => [
            'invitation' => [
                'name' => 'family_invitation',
                'language' => 'en_GB',
            ],
        ],
    ]);

    Http::preventStrayRequests();
});

it('normalises phone numbers', function () {
    expect(app(WhatsAppService::class)->normalisePhone('+234 (801) 234-5678'))->toBe('2348012345678');
});

it('sends and records a successful text message', function () {
    Http::fake([
        'graph.facebook.com/v25.0/1038448572690931/messages' => Http::response([
            'messaging_product' => 'whatsapp',
            'messages' => [['id' => 'wamid.success']],
        ]),
    ]);

    $result = app(WhatsAppService::class)->sendText('2348012345678', 'Hello');

    expect($result)->toBe([
        'success' => true,
        'wa_message_id' => 'wamid.success',
        'error' => null,
    ]);

    $this->assertDatabaseHas('whatsapp_messages', [
        'wa_message_id' => 'wamid.success',
        'direction' => 'outbound',
        'to' => '2348012345678',
        'type' => 'text',
        'body' => 'Hello',
        'status' => 'sent',
    ]);
});

it('sends and records a successful template message', function () {
    Http::fake([
        'graph.facebook.com/v25.0/1038448572690931/messages' => Http::response([
            'messages' => [['id' => 'wamid.template']],
        ]),
    ]);

    $result = app(WhatsAppService::class)->sendTemplate(
        to: '2348012345678',
        templateName: 'contribution_reminder',
        bodyParameters: ['Jane', 5000],
        languageCode: 'en_GB',
    );

    expect($result['success'])->toBeTrue()
        ->and($result['wa_message_id'])->toBe('wamid.template');

    $message = WhatsAppMessageModel::query()->where('wa_message_id', 'wamid.template')->firstOrFail();
    $payload = $message->payload;

    if (! is_array($payload)) {
        throw new RuntimeException('Expected WhatsApp message payload to be an array.');
    }

    expect($message->template_name)->toBe('contribution_reminder')
        ->and(stringValue(resultArray(resultArray($payload, 'template'), 'language'), 'code'))->toBe('en_GB');
});

it('sends invitations with the configured template', function () {
    Http::fake([
        'graph.facebook.com/v25.0/1038448572690931/messages' => Http::response([
            'messages' => [['id' => 'wamid.invitation']],
        ]),
    ]);

    $result = app(WhatsAppService::class)->sendInvitation(
        to: '+2348012345678',
        familyName: 'Smith Family',
        roleLabel: 'Member',
        acceptUrl: 'https://family.test/invitations/accept/token',
    );

    expect($result['success'])->toBeTrue()
        ->and($result['wa_message_id'])->toBe('wamid.invitation');

    $message = WhatsAppMessageModel::query()->where('wa_message_id', 'wamid.invitation')->firstOrFail();
    $payload = $message->payload;

    if (! is_array($payload)) {
        throw new RuntimeException('Expected WhatsApp message payload to be an array.');
    }

    $template = resultArray($payload, 'template');
    $components = resultArray($template, 'components');
    $bodyComponent = resultArray($components, 0);
    $parameters = resultArray($bodyComponent, 'parameters');

    expect($message->template_name)->toBe('family_invitation')
        ->and($message->to)->toBe('2348012345678')
        ->and($payload['type'] ?? null)->toBe('template')
        ->and($template['name'] ?? null)->toBe('family_invitation')
        ->and(stringValue(resultArray($template, 'language'), 'code'))->toBe('en_GB')
        ->and(resultArray($parameters, 0)['text'] ?? null)->toBe('Smith Family')
        ->and(resultArray($parameters, 1)['text'] ?? null)->toBe('Member')
        ->and(resultArray($parameters, 2)['text'] ?? null)->toBe('https://family.test/invitations/accept/token');
});

it('fails invitation sends when the invitation template is not configured', function () {
    config()->set('services.whatsapp.templates.invitation.name', null);

    Http::preventStrayRequests();

    $result = app(WhatsAppService::class)->sendInvitation(
        to: '+2348012345678',
        familyName: 'Smith Family',
        roleLabel: 'Member',
        acceptUrl: 'https://family.test/invitations/accept/token',
    );

    expect($result)->toBe([
        'success' => false,
        'wa_message_id' => null,
        'error' => 'WhatsApp invitation template is not configured.',
    ]);
});

it('records failed api responses without throwing', function () {
    Http::fake([
        'graph.facebook.com/v25.0/1038448572690931/messages' => Http::response([
            'error' => [
                'message' => 'Invalid recipient',
                'code' => 131030,
            ],
        ], 400),
    ]);

    $result = app(WhatsAppService::class)->sendText('2348012345678', 'Hello');

    expect($result)->toBe([
        'success' => false,
        'wa_message_id' => null,
        'error' => 'Invalid recipient',
    ]);

    $this->assertDatabaseHas('whatsapp_messages', [
        'wa_message_id' => null,
        'to' => '2348012345678',
        'status' => 'failed',
        'error_code' => '131030',
        'error_message' => 'Invalid recipient',
    ]);
});

it('handles whatsapp connection failures without throwing', function () {
    Http::fake([
        'graph.facebook.com/v25.0/1038448572690931/messages' => Http::failedConnection(),
    ]);

    $result = app(WhatsAppService::class)->sendText('2348012345678', 'Hello');

    expect($result['success'])->toBeFalse()
        ->and($result['error'])->toBeString();

    $this->assertDatabaseHas('whatsapp_messages', [
        'to' => '2348012345678',
        'status' => 'failed',
    ]);
});

it('logs when outbound recording fails', function () {
    WhatsAppMessageModel::factory()->create([
        'wa_message_id' => 'wamid.duplicate',
    ]);

    Http::fake([
        'graph.facebook.com/v25.0/1038448572690931/messages' => Http::response([
            'messages' => [['id' => 'wamid.duplicate']],
        ]),
    ]);

    Log::shouldReceive('warning')
        ->once()
        ->with('Failed to record outbound WhatsApp message', Mockery::on(
            fn (array $context): bool => $context['to'] === '2348012345678',
        ));

    app(WhatsAppService::class)->send(
        '2348012345678',
        (new WhatsAppMessage)->text('Hello'),
    );
});
