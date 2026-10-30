<?php

declare(strict_types=1);

use App\Enums\PaymentSource;
use App\Jobs\ProcessWhatsAppWebhook;
use App\Jobs\SendPaymentReceipt;
use App\Mail\PaymentReceiptMail;
use App\Models\Family;
use App\Models\PaymentBatch;
use App\Models\ReportArtifact;
use App\Models\User;
use App\Services\PaymentAllocationService;
use App\Services\ReportArtifactService;
use App\Services\WhatsAppService;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\Request;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Pipeline\Pipeline;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

beforeEach(function () {
    config([
        'queue.default' => 'database',
        'services.whatsapp.access_token' => 'test-token',
        'services.whatsapp.phone_number_id' => 'test-sender',
        'services.whatsapp.templates.payment_receipt.name' => 'payment_receipt',
        'services.whatsapp.templates.payment_receipt.language' => 'en',
    ]);
    Storage::fake('local');
    Mail::fake();
    Http::preventStrayRequests();
    $this->family = Family::factory()->create(['name' => 'Receipt Test Family', 'currency' => 'NGN']);
    $this->recorder = User::factory()->financialSecretary()->create(['family_id' => $this->family->id]);
    $this->member = User::factory()->member()->employed()->create([
        'family_id' => $this->family->id,
        'name' => 'Receipt Test Member',
        'whatsapp_phone' => '+2348012345678',
        'whatsapp_verified_at' => now(),
    ]);
});

function recordReceiptPayment(TestCase $test, PaymentSource $source = PaymentSource::Manual, ?string $key = null): PaymentBatch
{
    return app(PaymentAllocationService::class)->createBatch(
        member: $test->member, amount: 1000, paidAt: now(), recordedBy: $test->recorder,
        family: $test->family, source: $source, idempotencyKey: $key,
    );
}

it('queues both receipt channels after a successful payment and only once on replay', function (PaymentSource $source) {
    Queue::fake([SendPaymentReceipt::class]);
    $batch = recordReceiptPayment($this, $source, 'receipt-replay');
    $replayed = recordReceiptPayment($this, $source, 'receipt-replay');

    expect($replayed->id)->toBe($batch->id);
    expect($batch->receiptDeliveries()->where('status', 'queued')->count())->toBe(2);
    Queue::assertPushed(SendPaymentReceipt::class, 2);
    foreach (['mail', 'whatsapp'] as $channel) {
        Queue::assertPushed(SendPaymentReceipt::class, fn (SendPaymentReceipt $job): bool => $job->paymentBatchId === $batch->id
            && $job->recipientId === $this->member->id && $job->channel === $channel);
    }
    Mail::assertNothingSent();
    Http::assertNothingSent();
})->with([PaymentSource::Manual, PaymentSource::Paystack, PaymentSource::Correction]);

it('rolls back receipt jobs with an enclosing payment transaction', function () {
    expect(function () {
        DB::transaction(function () {
            recordReceiptPayment($this);
            expect(DB::table('jobs')->count())->toBe(2);
            throw new RuntimeException('Cancel recording');
        });
    })->toThrow(RuntimeException::class, 'Cancel recording');

    expect(PaymentBatch::count())->toBe(0)->and(DB::table('jobs')->count())->toBe(0);
});

it('persists receipt jobs only when the enclosing transaction commits', function () {
    DB::transaction(function () {
        recordReceiptPayment($this);
        expect(DB::table('jobs')->count())->toBe(2);
    });

    expect(DB::table('jobs')->count())->toBe(2);
});

it('rolls back a payment if its receipt jobs cannot be queued', function () {
    config(['queue.connections.database.table' => 'missing_receipt_jobs']);

    expect(fn () => recordReceiptPayment($this))->toThrow(QueryException::class);
    expect(PaymentBatch::count())->toBe(0)
        ->and(DB::table('payment_receipt_deliveries')->count())->toBe(0)
        ->and(DB::table('jobs')->count())->toBe(0);
});

it('refuses to record a payment when the notification queue uses a different database', function () {
    config(['queue.connections.database.connection' => 'different-database']);

    expect(fn () => recordReceiptPayment($this))->toThrow(RuntimeException::class, 'require the database queue on the application database');
    expect(PaymentBatch::count())->toBe(0)->and(DB::table('jobs')->count())->toBe(0);
});

it('keeps a receipt retry available if its job cannot be queued', function () {
    if (DB::connection()->getDriverName() !== 'sqlite') {
        $this->markTestSkipped('This queue failure simulation uses an SQLite trigger.');
    }
    $batch = recordReceiptPayment($this);
    $delivery = $batch->receiptDeliveries()->where('channel', 'mail')->firstOrFail();
    $delivery->update(['status' => 'failed', 'failed_at' => now()]);
    DB::statement("create trigger fail_receipt_retry before insert on jobs begin select raise(abort, 'queue unavailable'); end");

    $this->withoutExceptionHandling();
    expect(fn () => $this->actingAs($this->recorder)->post(route('payment-receipt-deliveries.retry', [
        'current_family' => $this->family->slug, 'delivery' => $delivery->id,
    ])))->toThrow(QueryException::class);

    expect($delivery->fresh()?->status)->toBe('failed')
        ->and(PaymentBatch::count())->toBe(1)
        ->and(DB::table('jobs')->count())->toBe(2);
});

it('does not queue a receipt retry if its delivery status changes concurrently', function () {
    if (DB::connection()->getDriverName() !== 'sqlite') {
        $this->markTestSkipped('This status race simulation uses an SQLite trigger.');
    }
    $batch = recordReceiptPayment($this);
    $delivery = $batch->receiptDeliveries()->where('channel', 'mail')->firstOrFail();
    $delivery->update(['status' => 'failed', 'failed_at' => now()]);
    DB::statement("create trigger simulate_receipt_status_race before update on payment_receipt_deliveries when old.status = 'failed' and new.status = 'queued' begin update payment_receipt_deliveries set status='accepted' where id=old.id; select raise(ignore); end");

    $this->actingAs($this->recorder)->post(route('payment-receipt-deliveries.retry', [
        'current_family' => $this->family->slug, 'delivery' => $delivery->id,
    ]))->assertRedirect()->assertSessionHas('error', 'Delivery status changed. Refresh and check it before retrying.');
    expect($delivery->fresh()?->status)->toBe('accepted')
        ->and(PaymentBatch::count())->toBe(1)
        ->and(DB::table('jobs')->count())->toBe(2);
});

it('does not queue receipts for historical imports or rejected allocations', function () {
    recordReceiptPayment($this, PaymentSource::Backfill);
    expect(fn () => app(PaymentAllocationService::class)->createBatch(
        member: $this->member, amount: 999999, paidAt: now(), recordedBy: $this->recorder, family: $this->family,
    ))->toThrow(InvalidArgumentException::class);

    expect(DB::table('jobs')->count())->toBe(0);
});

it('still emails a member who has not verified WhatsApp', function () {
    Queue::fake([SendPaymentReceipt::class]);
    $this->member->forceFill(['whatsapp_verified_at' => null])->save();
    recordReceiptPayment($this);
    Queue::assertPushed(SendPaymentReceipt::class, 1);
    Queue::assertPushed(SendPaymentReceipt::class, fn (SendPaymentReceipt $job): bool => $job->channel === 'mail');
});

it('emails the actual private PDF receipt to the paying member', function () {
    $batch = recordReceiptPayment($this);
    app()->call([new SendPaymentReceipt($batch->id, $this->member->id, 'mail'), 'handle']);

    expect($batch->receiptDeliveries()->where('channel', 'mail')->firstOrFail()->status)->toBe('accepted');

    $artifact = ReportArtifact::sole();
    expect(Storage::disk($artifact->disk)->get($artifact->path))->toStartWith('%PDF-');
    Mail::assertSent(PaymentReceiptMail::class, function (PaymentReceiptMail $mail) use ($batch, $artifact) {
        $mail->assertHasAttachment(Attachment::fromStorageDisk($artifact->disk, $artifact->path)
            ->as($artifact->filename)->withMime('application/pdf'));

        return $mail->hasTo($this->member->email) && ! $mail->hasTo($this->recorder->email)
            && $mail->batch->id === $batch->id;
    });
});

it('does not resend an accepted receipt if a job is replayed', function () {
    $batch = recordReceiptPayment($this);
    $job = new SendPaymentReceipt($batch->id, $this->member->id, 'mail');

    app()->call([$job, 'handle']);
    app()->call([$job, 'handle']);

    Mail::assertSent(PaymentReceiptMail::class, 1);
});

it('does not send the same receipt concurrently', function () {
    $batch = recordReceiptPayment($this);
    $first = new SendPaymentReceipt($batch->id, $this->member->id, 'mail');
    $second = (new SendPaymentReceipt($batch->id, $this->member->id, 'mail'))->withFakeQueueInteractions();
    $artifacts = app(ReportArtifactService::class);
    $mock = Mockery::mock(ReportArtifactService::class)->makePartial();
    $mock->shouldReceive('receipt')->once()->andReturnUsing(function (Family $family, PaymentBatch $payment, ?User $member) use ($second, $artifacts): ReportArtifact {
        app(Pipeline::class)->send($second)->through($second->middleware())
            ->then(fn (SendPaymentReceipt $job) => $job->handle($artifacts, app(WhatsAppService::class)));

        return $artifacts->receipt($family, $payment, $member);
    });

    app(Pipeline::class)->send($first)->through($first->middleware())
        ->then(fn (SendPaymentReceipt $job) => $job->handle($mock, app(WhatsAppService::class)));
    $second->assertReleased(90);
    app(Pipeline::class)->send($second)->through($second->middleware())
        ->then(fn (SendPaymentReceipt $job) => $job->handle($artifacts, app(WhatsAppService::class)));

    Mail::assertSent(PaymentReceiptMail::class, 1);
});

it('shows provider-confirmed WhatsApp delivery without claiming email delivery', function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.receipt']]])]);
    $batch = recordReceiptPayment($this);
    app()->call([new SendPaymentReceipt($batch->id, $this->member->id, 'mail'), 'handle']);
    app()->call([new SendPaymentReceipt($batch->id, $this->member->id, 'whatsapp'), 'handle']);
    $payload = ['entry' => [['changes' => [[
        'field' => 'messages', 'value' => ['statuses' => [['id' => 'wamid.receipt', 'status' => 'delivered']]],
    ]]]]];
    (new ProcessWhatsAppWebhook($payload))->handle();
    (new ProcessWhatsAppWebhook(['entry' => [['changes' => [[
        'field' => 'messages', 'value' => ['statuses' => [['id' => 'wamid.receipt', 'status' => 'sent']]],
    ]]]]]))->handle();

    $this->actingAs($this->recorder)->get(route('payments.index', ['current_family' => $this->family->slug]))
        ->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('receipts.0.deliveries.0.channel', 'mail')
        ->where('receipts.0.deliveries.0.status', 'accepted')
        ->where('receipts.0.deliveries.1.channel', 'whatsapp')
        ->where('receipts.0.deliveries.1.status', 'delivered')
        );
});

it('allows an officer to retry an unconfirmed receipt once without changing the payment', function () {
    Queue::fake([SendPaymentReceipt::class]);
    $batch = recordReceiptPayment($this);
    $delivery = $batch->receiptDeliveries()->where('channel', 'mail')->firstOrFail();
    $delivery->update(['status' => 'failed', 'failed_at' => now()]);
    $url = route('payment-receipt-deliveries.retry', ['current_family' => $this->family->slug, 'delivery' => $delivery->id]);

    $this->actingAs($this->recorder)->get(route('payments.index', ['current_family' => $this->family->slug]))
        ->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('receipts.0.deliveries.0.status', 'unconfirmed')
        ->where('receipts.0.deliveries.0.failure_reason', 'The delivery attempt did not complete. Check the recipient details and provider before retrying.'));

    $this->actingAs($this->member)->post($url)->assertForbidden();
    $otherFamily = Family::factory()->create();
    $otherAdmin = User::factory()->admin()->create(['family_id' => $otherFamily->id]);
    $this->actingAs($otherAdmin)->post(route('payment-receipt-deliveries.retry', [
        'current_family' => $otherFamily->slug, 'delivery' => $delivery->id,
    ]))->assertNotFound();
    $this->actingAs($this->recorder)->post($url)->assertRedirect()
        ->assertSessionHas('success', 'Receipt delivery queued again. The payment was not changed.');
    $this->actingAs($this->recorder)->post($url)->assertRedirect()->assertSessionHas('error');

    expect($delivery->fresh()?->status)->toBe('queued')
        ->and(PaymentBatch::count())->toBe(1)
        ->and($batch->fresh()?->total_amount)->toBe(1000);
    Queue::assertPushed(SendPaymentReceipt::class, 3);
});

it('retries a provider-reported WhatsApp failure without requeueing the email', function () {
    Queue::fake([SendPaymentReceipt::class]);
    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.retry']]])]);
    $batch = recordReceiptPayment($this);
    app()->call([new SendPaymentReceipt($batch->id, $this->member->id, 'whatsapp'), 'handle']);
    $delivery = $batch->receiptDeliveries()->where('channel', 'whatsapp')->firstOrFail();
    $delivery->whatsappMessage()->firstOrFail()->update(['status' => 'failed', 'error_code' => '131000', 'error_message' => 'secret provider detail']);
    $response = $this->actingAs($this->recorder)->get(route('payments.index', ['current_family' => $this->family->slug]))
        ->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->where('receipts.0.deliveries.1.failure_reason', 'WhatsApp reported delivery error 131000.')
        ->where('receipts.0.deliveries.1.can_retry', true)
    );
    $response->assertDontSee('secret provider detail');

    $this->actingAs($this->recorder)->post(route('payment-receipt-deliveries.retry', [
        'current_family' => $this->family->slug, 'delivery' => $delivery->id,
    ]))->assertRedirect()->assertSessionHas('success');

    expect($delivery->fresh()?->status)->toBe('queued')
        ->and($delivery->fresh()?->provider_message_id)->toBeNull()
        ->and(PaymentBatch::count())->toBe(1);
    Queue::assertPushed(SendPaymentReceipt::class, fn (SendPaymentReceipt $job): bool => $job->channel === 'whatsapp');
    Queue::assertPushed(SendPaymentReceipt::class, 3);
});

it('does not mark WhatsApp as accepted without a provider message ID', function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => []])]);
    $batch = recordReceiptPayment($this);
    $job = new SendPaymentReceipt($batch->id, $this->member->id, 'whatsapp');

    expect(fn () => app()->call([$job, 'handle']))->toThrow(RuntimeException::class, 'No provider message ID');
    $job->failed(new RuntimeException('No provider message ID'));
    expect($batch->receiptDeliveries()->where('channel', 'whatsapp')->firstOrFail()->displayStatus())->toBe('unconfirmed');
});

it('renders the member receipt amount date method family and authorized download link', function () {
    $batch = recordReceiptPayment($this);
    $artifact = app(ReportArtifactService::class)->receipt($this->family, $batch, $this->member);
    $url = route('payment-batches.receipt', ['current_family' => $this->family->slug, 'payment_batch' => $batch->id]);
    $mail = new PaymentReceiptMail($batch, $artifact, $url);

    $mail->assertSeeInHtml('NGN 1,000.00');
    $mail->assertSeeInHtml($this->member->name);
    $mail->assertSeeInHtml($this->family->name);
    $mail->assertSeeInHtml($batch->paid_at->toFormattedDateString());
    $mail->assertSeeInHtml('Cash');
    $mail->assertSeeInHtml($url);
});

it('sends the approved WhatsApp template with receipt details and the member download link', function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.receipt']]])]);
    $batch = recordReceiptPayment($this);
    app()->call([new SendPaymentReceipt($batch->id, $this->member->id, 'whatsapp'), 'handle']);

    Http::assertSent(function (Request $request) use ($batch) {
        return $request['to'] === '2348012345678'
            && $request['type'] === 'template'
            && $request['template'] === [
                'name' => 'payment_receipt', 'language' => ['code' => 'en'],
                'components' => [[
                    'type' => 'body',
                    'parameters' => array_map(fn (string $text): array => ['type' => 'text', 'text' => $text], [
                        $this->member->name, (string) $batch->receipt_number, 'NGN 1,000.00',
                        $this->family->name, $batch->paid_at->toFormattedDateString(),
                        route('payment-batches.receipt', ['current_family' => $this->family->slug, 'payment_batch' => $batch->id]),
                    ]),
                ]],
            ];
    });
    Mail::assertNothingSent();
});

it('retries WhatsApp failures without resending the email', function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'Provider unavailable']], 503)]);
    $batch = recordReceiptPayment($this);
    app()->call([new SendPaymentReceipt($batch->id, $this->member->id, 'mail'), 'handle']);
    $job = new SendPaymentReceipt($batch->id, $this->member->id, 'whatsapp');

    expect(fn () => app()->call([$job, 'handle']))->toThrow(RuntimeException::class, 'WhatsApp payment receipt delivery failed');
    expect(fn () => app()->call([$job, 'handle']))->toThrow(RuntimeException::class);
    Mail::assertSentCount(1);
    Http::assertSentCount(2);
});

it('does not disclose a receipt to a different member or a no longer verified WhatsApp number', function () {
    $batch = recordReceiptPayment($this);
    app()->call([new SendPaymentReceipt($batch->id, $this->recorder->id, 'mail'), 'handle']);
    $this->member->forceFill(['whatsapp_verified_at' => null])->save();
    app()->call([new SendPaymentReceipt($batch->id, $this->member->id, 'whatsapp'), 'handle']);

    Mail::assertNothingSent();
    Http::assertNothingSent();
});

it('skips receipts for missing or archived payment recipients', function (string $case) {
    $batch = recordReceiptPayment($this);
    if ($case === 'membership') {
        $batch->membership()->firstOrFail()->forceFill(['archived_at' => now()])->save();
    } elseif ($case === 'family') {
        $this->family->forceFill(['archived_at' => now()])->save();
    }
    $id = $case === 'missing' ? $batch->id + 1000 : $batch->id;
    app()->call([new SendPaymentReceipt($id, $this->member->id, 'mail'), 'handle']);

    Mail::assertNothingSent();
    Http::assertNothingSent();
})->with(['missing', 'membership', 'family']);

it('backs off retries and reports exhausted receipt delivery without recipient details', function () {
    $job = new SendPaymentReceipt(123, $this->member->id, 'whatsapp');
    expect($job->backoff())->toBe([60, 300, 900])
        ->and(collect($job->middleware())->filter(fn (object $middleware): bool => $middleware instanceof RateLimited))->toHaveCount(1)
        ->and(collect((new SendPaymentReceipt(123, $this->member->id, 'mail'))->middleware())->filter(fn (object $middleware): bool => $middleware instanceof RateLimited))->toBeEmpty();
    Log::shouldReceive('error')->once()->with('Payment receipt delivery failed', [
        'payment_batch_id' => 123, 'channel' => 'whatsapp', 'error' => 'Delivery unavailable',
    ]);
    $job->failed(new RuntimeException('Delivery unavailable'));
});
