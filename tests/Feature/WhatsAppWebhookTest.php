<?php

use App\Jobs\ProcessWhatsAppWebhook;
use App\Models\User;
use App\Models\WhatsAppMessage;
use Illuminate\Support\Facades\Bus;

beforeEach(function () {
    config()->set('services.whatsapp', [
        'access_token' => 'test-token',
        'phone_number_id' => '1038448572690931',
        'business_account_id' => '965423126197935',
        'api_version' => 'v25.0',
        'base_url' => 'https://graph.facebook.com',
        'webhook_verify_token' => 'verify-token-abc',
        'app_secret' => 'app-secret-xyz',
    ]);
});

describe('WhatsApp webhook verification (GET)', function () {
    it('echoes hub.challenge with valid verify token', function () {
        $response = $this->get('/webhooks/whatsapp?hub_mode=subscribe&hub_verify_token=verify-token-abc&hub_challenge=12345');

        $response->assertOk();
        expect($response->getContent())->toBe('12345');
    });

    it('rejects an invalid verify token', function () {
        $response = $this->get('/webhooks/whatsapp?hub_mode=subscribe&hub_verify_token=wrong-token&hub_challenge=12345');

        $response->assertForbidden();
    });

    it('rejects when hub_mode is missing or wrong', function () {
        $this->get('/webhooks/whatsapp?hub_verify_token=verify-token-abc&hub_challenge=12345')
            ->assertForbidden();
    });
});

describe('WhatsApp webhook handler (POST)', function () {
    it('dispatches the processing job with a valid signature', function () {
        Bus::fake();

        $payload = [
            'object' => 'whatsapp_business_account',
            'entry' => [],
        ];

        $body = json_encode($payload);
        $signature = 'sha256='.hash_hmac('sha256', $body, 'app-secret-xyz');

        $response = $this->call(
            'POST',
            '/webhooks/whatsapp',
            [],
            [],
            [],
            [
                'HTTP_X-Hub-Signature-256' => $signature,
                'CONTENT_TYPE' => 'application/json',
            ],
            $body,
        );

        $response->assertOk();

        Bus::assertDispatched(ProcessWhatsAppWebhook::class);
    });

    it('rejects requests with an invalid signature', function () {
        Bus::fake();

        $payload = ['object' => 'whatsapp_business_account', 'entry' => []];
        $body = json_encode($payload);

        $response = $this->call(
            'POST',
            '/webhooks/whatsapp',
            [],
            [],
            [],
            [
                'HTTP_X-Hub-Signature-256' => 'sha256=deadbeef',
                'CONTENT_TYPE' => 'application/json',
            ],
            $body,
        );

        $response->assertForbidden();

        Bus::assertNotDispatched(ProcessWhatsAppWebhook::class);
    });

    it('ignores payloads with an unrecognised object', function () {
        Bus::fake();

        $payload = ['object' => 'something_else', 'entry' => []];
        $body = json_encode($payload);
        $signature = 'sha256='.hash_hmac('sha256', $body, 'app-secret-xyz');

        $this->call(
            'POST',
            '/webhooks/whatsapp',
            [],
            [],
            [],
            ['HTTP_X-Hub-Signature-256' => $signature, 'CONTENT_TYPE' => 'application/json'],
            $body,
        )->assertOk();

        Bus::assertNotDispatched(ProcessWhatsAppWebhook::class);
    });
});

describe('ProcessWhatsAppWebhook job', function () {
    it('updates the status of an outbound message', function () {
        $message = WhatsAppMessage::factory()->create([
            'wa_message_id' => 'wamid.outbound-1',
            'direction' => 'outbound',
            'status' => 'sent',
        ]);

        $payload = [
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'changes' => [[
                    'field' => 'messages',
                    'value' => [
                        'statuses' => [[
                            'id' => 'wamid.outbound-1',
                            'status' => 'delivered',
                        ]],
                    ],
                ]],
            ]],
        ];

        (new ProcessWhatsAppWebhook($payload))->handle();

        expect($message->fresh()->status)->toBe('delivered');
    });

    it('records an inbound text message and links it to the matching user', function () {
        $user = User::factory()->withVerifiedWhatsApp('+2348012345678')->create();

        $payload = [
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'changes' => [[
                    'field' => 'messages',
                    'value' => [
                        'metadata' => ['phone_number_id' => '1038448572690931'],
                        'messages' => [[
                            'id' => 'wamid.inbound-1',
                            'from' => '2348012345678',
                            'type' => 'text',
                            'timestamp' => (string) now()->timestamp,
                            'text' => ['body' => 'Hello'],
                        ]],
                    ],
                ]],
            ]],
        ];

        (new ProcessWhatsAppWebhook($payload))->handle();

        $stored = WhatsAppMessage::query()->where('wa_message_id', 'wamid.inbound-1')->first();

        expect($stored)->not->toBeNull()
            ->and($stored->direction)->toBe('inbound')
            ->and($stored->body)->toBe('Hello')
            ->and($stored->user_id)->toBe($user->id)
            ->and($stored->family_id)->toBe($user->family_id);
    });

    it('does not duplicate inbound messages with the same wa_message_id', function () {
        WhatsAppMessage::factory()->inbound()->create([
            'wa_message_id' => 'wamid.dup',
        ]);

        $payload = [
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'changes' => [[
                    'field' => 'messages',
                    'value' => [
                        'messages' => [[
                            'id' => 'wamid.dup',
                            'from' => '2348012345678',
                            'type' => 'text',
                            'timestamp' => (string) now()->timestamp,
                            'text' => ['body' => 'Hello again'],
                        ]],
                    ],
                ]],
            ]],
        ];

        (new ProcessWhatsAppWebhook($payload))->handle();

        expect(WhatsAppMessage::query()->where('wa_message_id', 'wamid.dup')->count())->toBe(1);
    });
});
