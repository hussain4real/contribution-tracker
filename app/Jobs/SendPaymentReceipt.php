<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Mail\PaymentReceiptMail;
use App\Models\PaymentBatch;
use App\Models\PaymentReceiptDelivery;
use App\Models\User;
use App\Services\ReportArtifactService;
use App\Services\TransactionalNotificationQueue;
use App\Services\WhatsAppService;
use App\Support\CurrencyFormatter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Throwable;

class SendPaymentReceipt implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 60;

    /** @param 'mail'|'whatsapp' $channel */
    public function __construct(
        public readonly int $paymentBatchId,
        public readonly int $recipientId,
        public readonly string $channel,
    ) {
        $this->onConnection(app(TransactionalNotificationQueue::class)->connection());
        $this->beforeCommit();
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 300, 900];
    }

    /** @return list<object> */
    public function middleware(): array
    {
        $middleware = [(new WithoutOverlapping("{$this->paymentBatchId}:{$this->channel}"))->releaseAfter(90)->expireAfter(180)];
        if ($this->channel === 'whatsapp') {
            $middleware[] = (new RateLimited('whatsapp-notifications'))->releaseAfter(60);
        }

        return $middleware;
    }

    public function failed(?Throwable $exception): void
    {
        PaymentReceiptDelivery::query()
            ->where('payment_batch_id', $this->paymentBatchId)
            ->where('channel', $this->channel)
            ->where('status', 'queued')
            ->update(['status' => 'failed', 'failed_at' => now()]);

        Log::error('Payment receipt delivery failed', [
            'payment_batch_id' => $this->paymentBatchId,
            'channel' => $this->channel,
            'error' => $exception?->getMessage(),
        ]);
    }

    public function handle(ReportArtifactService $artifacts, WhatsAppService $whatsApp): void
    {
        $batch = PaymentBatch::query()->with(['family', 'membership.user'])->find($this->paymentBatchId);
        $membership = $batch?->membership;
        $member = $membership?->user;

        if (! $batch instanceof PaymentBatch || ! $member instanceof User
            || $member->id !== $this->recipientId || $membership->isArchived()
            || $batch->family->isArchived() || $batch->isReversed()) {
            PaymentReceiptDelivery::query()
                ->where('payment_batch_id', $this->paymentBatchId)
                ->where('channel', $this->channel)
                ->where('status', 'queued')
                ->update(['status' => 'unavailable']);

            return;
        }

        $delivery = PaymentReceiptDelivery::query()->firstOrCreate([
            'payment_batch_id' => $batch->id,
            'channel' => $this->channel,
        ], ['status' => 'queued']);
        if ($delivery->status !== 'queued') {
            return;
        }
        $delivery->update(['attempted_at' => now()]);

        $receiptUrl = route('payment-batches.receipt', [
            'current_family' => $batch->family->slug,
            'payment_batch' => $batch->id,
        ]);

        if ($this->channel === 'mail') {
            $artifact = $artifacts->receipt($batch->family, $batch, $member);
            Mail::to($member->email)->send(new PaymentReceiptMail($batch, $artifact, $receiptUrl));

            $delivery->update(['status' => 'accepted', 'accepted_at' => now(), 'failed_at' => null]);

            return;
        }

        $phone = $member->routeNotificationForWhatsApp();

        if ($phone === null) {
            $delivery->update(['status' => 'unavailable']);

            return;
        }

        $result = $whatsApp->sendTemplate(
            to: $whatsApp->normalisePhone($phone),
            templateName: Config::string('services.whatsapp.templates.payment_receipt.name'),
            bodyParameters: [
                $batch->member_name,
                (string) $batch->receipt_number,
                CurrencyFormatter::format($batch->total_amount, $batch->family->currency),
                $batch->family->name,
                $batch->paid_at->toFormattedDateString(),
                $receiptUrl,
            ],
            languageCode: Config::string('services.whatsapp.templates.payment_receipt.language'),
            familyId: $batch->family_id,
            userId: $member->id,
        );

        throw_unless($result['success'] && filled($result['wa_message_id']), RuntimeException::class, 'WhatsApp payment receipt delivery failed: '.($result['error'] ?? 'No provider message ID was returned'));
        $delivery->update([
            'status' => 'accepted',
            'provider_message_id' => $result['wa_message_id'],
            'accepted_at' => now(),
            'failed_at' => null,
        ]);
    }
}
