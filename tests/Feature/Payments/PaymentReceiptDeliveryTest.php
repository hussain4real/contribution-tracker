<?php

declare(strict_types=1);

use App\Enums\PaymentSource;
use App\Jobs\SendPaymentReceipt;
use App\Mail\PaymentReceiptMail;
use App\Models\Family;
use App\Models\PaymentBatch;
use App\Models\ReportArtifact;
use App\Models\User;
use App\Services\PaymentAllocationService;
use App\Services\ReportArtifactService;
use Illuminate\Http\Client\Request;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
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
    $this->family = Family::factory()->create(['currency' => 'NGN']);
    $this->recorder = User::factory()->financialSecretary()->create(['family_id' => $this->family->id]);
    $this->member = User::factory()->member()->employed()->create([
        'family_id' => $this->family->id,
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
    Queue::assertPushed(SendPaymentReceipt::class, 2);
    foreach (['mail', 'whatsapp'] as $channel) {
        Queue::assertPushed(SendPaymentReceipt::class, fn (SendPaymentReceipt $job): bool => $job->paymentBatchId === $batch->id
            && $job->recipientId === $this->member->id && $job->channel === $channel);
    }
    Mail::assertNothingSent();
    Http::assertNothingSent();
})->with([PaymentSource::Manual, PaymentSource::Paystack, PaymentSource::Correction]);

it('does not queue receipts before an enclosing transaction commits or after it rolls back', function () {
    expect(function () {
        DB::transaction(function () {
            recordReceiptPayment($this);
            expect(DB::table('jobs')->count())->toBe(0);
            throw new RuntimeException('Cancel recording');
        });
    })->toThrow(RuntimeException::class, 'Cancel recording');

    expect(PaymentBatch::count())->toBe(0)->and(DB::table('jobs')->count())->toBe(0);
});

it('persists receipt jobs only when the enclosing transaction commits', function () {
    DB::transaction(function () {
        recordReceiptPayment($this);
        expect(DB::table('jobs')->count())->toBe(0);
    });

    expect(DB::table('jobs')->count())->toBe(2);
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

    $artifact = ReportArtifact::sole();
    expect(Storage::disk($artifact->disk)->get($artifact->path))->toStartWith('%PDF-');
    Mail::assertSent(PaymentReceiptMail::class, function (PaymentReceiptMail $mail) use ($batch, $artifact) {
        $mail->assertHasAttachment(Attachment::fromStorageDisk($artifact->disk, $artifact->path)
            ->as($artifact->filename)->withMime('application/pdf'));

        return $mail->hasTo($this->member->email) && ! $mail->hasTo($this->recorder->email)
            && $mail->batch->id === $batch->id;
    });
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
        ->and($job->middleware())->toHaveCount(1)
        ->and((new SendPaymentReceipt(123, $this->member->id, 'mail'))->middleware())->toBe([]);
    Log::shouldReceive('error')->once()->with('Payment receipt delivery failed', [
        'payment_batch_id' => 123, 'channel' => 'whatsapp', 'error' => 'Delivery unavailable',
    ]);
    $job->failed(new RuntimeException('Delivery unavailable'));
});
