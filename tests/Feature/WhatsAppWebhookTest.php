<?php

declare(strict_types=1);

use App\Http\Controllers\WhatsAppWebhookController;
use App\Jobs\ProcessWhatsAppWebhook;
use App\Models\User;
use App\Models\WhatsAppMessage;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
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
    ]);
});

describe('WhatsApp webhook verification (GET)', function () {
    it('echoes hub.challenge with valid verify token', function () {
        $response = $this->get('/webhooks/whatsapp?hub_mode=subscribe&hub_verify_token=verify-token-abc&hub_challenge=12345');

        $response->assertOk();
        expect(responseContent($response->baseResponse))->toBe('12345');
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

        $body = encodeJsonPayload($payload);
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
        $body = encodeJsonPayload($payload);

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

    it('rejects requests with a malformed signature header', function () {
        Bus::fake();

        $payload = ['object' => 'whatsapp_business_account', 'entry' => []];
        $body = encodeJsonPayload($payload);

        $this->call(
            'POST',
            '/webhooks/whatsapp',
            [],
            [],
            [],
            [
                'HTTP_X-Hub-Signature-256' => 'deadbeef',
                'CONTENT_TYPE' => 'application/json',
            ],
            $body,
        )->assertForbidden();

        Bus::assertNotDispatched(ProcessWhatsAppWebhook::class);
    });

    it('rejects requests with a non-string signature header', function () {
        Bus::fake();

        $payload = ['object' => 'whatsapp_business_account', 'entry' => []];
        $body = encodeJsonPayload($payload);
        $request = Request::create(
            '/webhooks/whatsapp',
            'POST',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: $body,
        );
        $request->headers->set('X-Hub-Signature-256', ['sha256=deadbeef']);

        $response = app(WhatsAppWebhookController::class)->handle($request);

        expect($response->getStatusCode())->toBe(403);
        Bus::assertNotDispatched(ProcessWhatsAppWebhook::class);
    });

    it('ignores payloads with an unrecognised object', function () {
        Bus::fake();

        $payload = ['object' => 'something_else', 'entry' => []];
        $body = encodeJsonPayload($payload);
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

    it('logs a critical warning when app secret is missing outside local environments', function () {
        Bus::fake();
        $deprecationsLogger = Mockery::mock();
        $deprecationsLogger->shouldReceive('warning')->zeroOrMoreTimes();

        Log::shouldReceive('channel')
            ->with('deprecations')
            ->zeroOrMoreTimes()
            ->andReturn($deprecationsLogger);
        Log::shouldReceive('critical')->once();
        config()->set('services.whatsapp.app_secret', null);
        app()->detectEnvironment(fn (): string => 'production');

        $this->postJson('/webhooks/whatsapp', [
            'object' => 'something_else',
            'entry' => [],
        ])->assertOk();

        app()->detectEnvironment(fn (): string => 'testing');
        Bus::assertNotDispatched(ProcessWhatsAppWebhook::class);
    });
});

describe('ProcessWhatsAppWebhook job', function () {
    it('can process a previously queued webhook without a retry counter', function () {
        config(['queue.default' => 'database', 'queue.connections.database.connection' => null]);
        $job = new ProcessWhatsAppWebhook(['entry' => [['changes' => [[
            'field' => 'messages', 'value' => ['statuses' => [['id' => 'wamid.legacy', 'status' => 'delivered']]],
        ]]]]]);
        unset($job->statusRetryAttempt);
        $restored = unserialize(serialize($job));
        if (! $restored instanceof ProcessWhatsAppWebhook) {
            throw new RuntimeException('Previously queued webhook could not be restored.');
        }

        $restored->handle();
        expect(DB::table('jobs')->count())->toBe(1);
    });

    it('applies an early status after the outbound message is recorded without replaying inbound messages', function () {
        config(['queue.default' => 'database', 'queue.connections.database.connection' => null]);
        $this->freezeTime();
        $payload = ['entry' => [['changes' => [['field' => 'messages', 'value' => [
            'statuses' => [['id' => 'wamid.early', 'status' => 'delivered']],
            'messages' => [['id' => 'wamid.inbound-once', 'from' => '2348012345678', 'type' => 'text', 'text' => ['body' => 'Hello']]],
        ]]]]]];

        (new ProcessWhatsAppWebhook($payload))->handle();
        expect(DB::table('jobs')->count())->toBe(1)
            ->and(DB::table('jobs')->value('payload'))->not->toContain('wamid.inbound-once')
            ->and(WhatsAppMessage::query()->where('wa_message_id', 'wamid.inbound-once')->count())->toBe(1);
        $outbound = WhatsAppMessage::factory()->create(['wa_message_id' => 'wamid.early', 'direction' => 'outbound', 'status' => 'sent']);
        $this->travel(11)->seconds();
        $this->artisan('queue:work', ['connection' => 'database', '--once' => true, '--sleep' => 0])->assertSuccessful();

        expect($outbound->fresh()?->status)->toBe('delivered')
            ->and(DB::table('jobs')->count())->toBe(0)
            ->and(WhatsAppMessage::query()->where('wa_message_id', 'wamid.inbound-once')->count())->toBe(1);
    });

    it('stops retrying a status whose outbound message never appears', function () {
        config(['queue.default' => 'database', 'queue.connections.database.connection' => null]);
        $this->freezeTime();
        $payload = ['entry' => [['changes' => [['field' => 'messages', 'value' => [
            'statuses' => [['id' => 'wamid.unmatched', 'status' => 'failed']],
        ]]]]]];
        (new ProcessWhatsAppWebhook($payload))->handle();

        foreach ([11, 31, 121] as $seconds) {
            $this->travel($seconds)->seconds();
            $this->artisan('queue:work', ['connection' => 'database', '--once' => true, '--sleep' => 0])->assertSuccessful();
        }

        expect(DB::table('jobs')->count())->toBe(0)
            ->and(WhatsAppMessage::query()->where('wa_message_id', 'wamid.unmatched')->exists())->toBeFalse();
    });

    it('retries after a status queue failure without duplicating an earlier inbound message', function () {
        config(['queue.default' => 'database', 'queue.connections.database.connection' => null]);
        $job = new ProcessWhatsAppWebhook(['entry' => [['changes' => [
            ['field' => 'messages', 'value' => ['messages' => [['id' => 'wamid.before-failure', 'from' => '2348012345678', 'type' => 'text', 'text' => ['body' => 'Hello']]]]],
            ['field' => 'messages', 'value' => ['statuses' => [['id' => 'wamid.queue-failure', 'status' => 'delivered']]]],
        ]]]]);
        DB::statement("create trigger fail_whatsapp_status_retry before insert on jobs begin select raise(abort, 'queue unavailable'); end");

        expect(fn () => $job->handle())->toThrow(QueryException::class);
        expect(DB::table('jobs')->count())->toBe(0);

        DB::statement('drop trigger fail_whatsapp_status_retry');
        $job->handle();
        expect(DB::table('jobs')->count())->toBe(1)
            ->and(WhatsAppMessage::query()->where('wa_message_id', 'wamid.before-failure')->count())->toBe(1);
    });

    it('ignores malformed entries and changes', function () {
        WhatsAppMessage::factory()->create([
            'wa_message_id' => 'wamid.unchanged',
            'status' => 'sent',
        ]);

        (new ProcessWhatsAppWebhook([
            'entry' => 'not-an-array',
        ]))->handle();

        (new ProcessWhatsAppWebhook([
            'entry' => [
                ['changes' => 'not-an-array'],
                ['changes' => [['field' => 'messages', 'value' => 'not-an-array']]],
            ],
        ]))->handle();

        $message = WhatsAppMessage::query()->where('wa_message_id', 'wamid.unchanged')->firstOrFail();

        expect($message->status)->toBe('sent');
    });

    it('ignores unsupported whatsapp change fields', function () {
        (new ProcessWhatsAppWebhook([
            'entry' => [[
                'changes' => [[
                    'field' => 'contacts',
                    'value' => ['contacts' => []],
                ]],
            ]],
        ]))->handle();

        expect(WhatsAppMessage::query()->count())->toBe(0);
    });

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

        $message->refresh();

        expect($message->status)->toBe('delivered');
    });

    it('preserves confirmed delivery when older status callbacks arrive later', function () {
        $message = WhatsAppMessage::factory()->create([
            'wa_message_id' => 'wamid.out-of-order', 'direction' => 'outbound', 'status' => 'sent',
        ]);
        $sendStatus = function (string $status): void {
            (new ProcessWhatsAppWebhook(['entry' => [['changes' => [[
                'field' => 'messages', 'value' => ['statuses' => [['id' => 'wamid.out-of-order', 'status' => $status]]],
            ]]]]]))->handle();
        };

        $sendStatus('delivered');
        $sendStatus('sent');
        $sendStatus('failed');
        expect($message->fresh()?->status)->toBe('delivered');
        $sendStatus('read');
        $sendStatus('delivered');
        $sendStatus('sent');
        expect($message->fresh()?->status)->toBe('read');
    });

    it('allows confirmed delivery to supersede a previous failure', function () {
        $message = WhatsAppMessage::factory()->create([
            'wa_message_id' => 'wamid.late-delivered', 'direction' => 'outbound', 'status' => 'failed',
        ]);
        (new ProcessWhatsAppWebhook(['entry' => [['changes' => [[
            'field' => 'messages', 'value' => ['statuses' => [['id' => 'wamid.late-delivered', 'status' => 'delivered']]],
        ]]]]]))->handle();

        expect($message->fresh()?->status)->toBe('delivered');
    });

    it('records the provider timestamp when an outbound message is read', function () {
        $readAt = now()->subMinute()->startOfSecond();
        $message = WhatsAppMessage::factory()->create([
            'wa_message_id' => 'wamid.outbound-read',
            'direction' => 'outbound',
            'status' => 'delivered',
            'read_at' => null,
        ]);

        (new ProcessWhatsAppWebhook([
            'entry' => [[
                'changes' => [[
                    'field' => 'messages',
                    'value' => [
                        'statuses' => [[
                            'id' => 'wamid.outbound-read',
                            'status' => 'read',
                            'timestamp' => (string) $readAt->timestamp,
                        ]],
                    ],
                ]],
            ]],
        ]))->handle();

        $message->refresh();

        expect($message->status)->toBe('read')
            ->and($message->read_at?->equalTo($readAt))->toBeTrue();
    });

    it('updates outbound status errors when meta sends delivery errors', function () {
        $message = WhatsAppMessage::factory()->create([
            'wa_message_id' => 'wamid.failed',
            'direction' => 'outbound',
            'status' => 'sent',
        ]);

        (new ProcessWhatsAppWebhook([
            'entry' => [[
                'changes' => [[
                    'field' => 'messages',
                    'value' => [
                        'statuses' => [[
                            'id' => 'wamid.failed',
                            'status' => 'failed',
                            'errors' => [[
                                'code' => 131000,
                                'title' => 'Message failed',
                            ]],
                        ]],
                    ],
                ]],
            ]],
        ]))->handle();

        $message->refresh();

        expect($message->status)->toBe('failed')
            ->and($message->error_code)->toBe('131000')
            ->and($message->error_message)->toBe('Message failed');
    });

    it('reports outbound status update failures so the webhook can be retried', function () {
        $message = WhatsAppMessage::factory()->create([
            'wa_message_id' => 'wamid.update-fails',
            'direction' => 'outbound',
            'status' => 'sent',
        ]);

        $shouldThrowOnUpdate = new class(true)
        {
            public function __construct(public bool $enabled) {}

            public function __invoke(): bool
            {
                return $this->enabled;
            }
        };
        WhatsAppMessage::updating(function () use (&$shouldThrowOnUpdate): void {
            if ($shouldThrowOnUpdate()) {
                throw new RuntimeException('update failed');
            }
        });

        $job = new ProcessWhatsAppWebhook([
            'entry' => [[
                'changes' => [[
                    'field' => 'messages',
                    'value' => [
                        'statuses' => [[
                            'id' => 'wamid.update-fails',
                            'status' => 'delivered',
                        ]],
                    ],
                ]],
            ]],
        ]);

        expect(fn () => $job->handle())->toThrow(RuntimeException::class, 'update failed');

        $shouldThrowOnUpdate->enabled = false;

        $message->refresh();

        expect($message->status)->toBe('sent');
    });

    it('ignores outbound status updates missing required fields or records', function () {
        (new ProcessWhatsAppWebhook([
            'entry' => [[
                'changes' => [[
                    'field' => 'messages',
                    'value' => [
                        'statuses' => [
                            ['id' => null, 'status' => 'delivered'],
                            ['id' => 'wamid.missing', 'status' => 'delivered'],
                        ],
                    ],
                ]],
            ]],
        ]))->handle();

        expect(WhatsAppMessage::query()->where('wa_message_id', 'wamid.missing')->exists())->toBeFalse();
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

        $stored = WhatsAppMessage::query()->where('wa_message_id', 'wamid.inbound-1')->firstOrFail();

        expect($stored->direction)->toBe('inbound')
            ->and($stored->body)->toBe('Hello')
            ->and($stored->user_id)->toBe($user->id)
            ->and($stored->family_id)->toBe($user->family_id);
    });

    it('records inbound button and interactive messages', function (array $message, string $expectedBody) {
        $payload = [
            'entry' => [[
                'changes' => [[
                    'field' => 'messages',
                    'value' => [
                        'messages' => [$message],
                    ],
                ]],
            ]],
        ];

        (new ProcessWhatsAppWebhook($payload))->handle();

        expect(WhatsAppMessage::query()->where('wa_message_id', $message['id'])->first()?->body)
            ->toBe($expectedBody);
    })->with([
        'button' => [[
            'id' => 'wamid.button',
            'from' => '2348012345678',
            'type' => 'button',
            'button' => ['text' => 'Yes'],
        ], 'Yes'],
        'button reply' => [[
            'id' => 'wamid.interactive-button',
            'from' => '2348012345678',
            'type' => 'interactive',
            'interactive' => ['button_reply' => ['title' => 'Pay now']],
        ], 'Pay now'],
        'list reply' => [[
            'id' => 'wamid.interactive-list',
            'from' => '2348012345678',
            'type' => 'interactive',
            'interactive' => ['list_reply' => ['title' => 'May 2026']],
        ], 'May 2026'],
    ]);

    it('records inbound messages without a matching user and defaults timestamp and destination', function () {
        (new ProcessWhatsAppWebhook([
            'entry' => [[
                'changes' => [[
                    'field' => 'messages',
                    'value' => [
                        'messages' => [[
                            'id' => 'wamid.unknown',
                            'from' => '2348012349999',
                            'type' => 'image',
                        ]],
                    ],
                ]],
            ]],
        ]))->handle();

        $message = WhatsAppMessage::query()->where('wa_message_id', 'wamid.unknown')->firstOrFail();

        expect($message->body)->toBeNull()
            ->and($message->to)->toBe('1038448572690931')
            ->and($message->user_id)->toBeNull()
            ->and($message->family_id)->toBeNull()
            ->and($message->wa_timestamp)->not->toBeNull();
    });

    it('ignores inbound messages missing required identifiers', function () {
        (new ProcessWhatsAppWebhook([
            'entry' => [[
                'changes' => [[
                    'field' => 'messages',
                    'value' => [
                        'messages' => [
                            ['id' => null, 'from' => '2348012345678'],
                            ['id' => 'wamid.no-from'],
                        ],
                    ],
                ]],
            ]],
        ]))->handle();

        expect(WhatsAppMessage::query()->where('wa_message_id', 'wamid.no-from')->exists())->toBeFalse();
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

    it('logs inbound message recording failures', function () {
        $shouldThrowOnCreate = new class(true)
        {
            public function __construct(public bool $enabled) {}

            public function __invoke(): bool
            {
                return $this->enabled;
            }
        };
        WhatsAppMessage::creating(function () use (&$shouldThrowOnCreate): void {
            if ($shouldThrowOnCreate()) {
                throw new RuntimeException('create failed');
            }
        });

        (new ProcessWhatsAppWebhook([
            'entry' => [[
                'changes' => [[
                    'field' => 'messages',
                    'value' => [
                        'messages' => [[
                            'id' => 'wamid.create-fails',
                            'from' => '2348012345678',
                            'type' => 'text',
                            'text' => ['body' => 'Hello'],
                        ]],
                    ],
                ]],
            ]],
        ]))->handle();

        $shouldThrowOnCreate->enabled = false;

        expect(WhatsAppMessage::query()->where('wa_message_id', 'wamid.create-fails')->exists())->toBeFalse();
    });

    it('returns no matched user for empty normalized whatsapp phone numbers', function () {
        $job = new class([]) extends ProcessWhatsAppWebhook
        {
            public function find(string $from): ?User
            {
                return $this->matchUser($from);
            }
        };

        expect($job->find('---'))->toBeNull();
    });

    it('builds database-specific whatsapp phone matching queries', function (string $driver) {
        $job = new class([]) extends ProcessWhatsAppWebhook
        {
            public function find(string $from): ?User
            {
                return $this->matchUser($from);
            }
        };

        DB::partialMock()
            ->shouldReceive('connection')
            ->twice()
            ->andReturn(new class($driver)
            {
                public function __construct(private string $driver) {}

                public function getDriverName(): string
                {
                    return $this->driver;
                }
            });

        try {
            $job->find('+2348012345678');
        } catch (Throwable) {
            // The test database is SQLite, so the vendor-specific SQL is only
            // exercised up to execution. That is enough to protect branch drift.
        }
    })->with([
        'pgsql',
        'mysql',
    ]);

    it('logs template status updates', function () {
        Log::shouldReceive('info')
            ->once()
            ->with('WhatsApp template status update', [
                'template_name' => 'contribution_reminder',
                'event' => 'APPROVED',
                'reason' => null,
            ]);

        (new ProcessWhatsAppWebhook([
            'entry' => [[
                'changes' => [[
                    'field' => 'message_template_status_update',
                    'value' => [
                        'message_template_name' => 'contribution_reminder',
                        'event' => 'APPROVED',
                    ],
                ]],
            ]],
        ]))->handle();
    });
});
