<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Mail\PaymentReceiptMail;
use App\Models\PaymentBatch;
use App\Models\User;
use App\Services\ReportArtifactService;
use App\Services\WhatsAppService;
use App\Support\CurrencyFormatter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\RateLimited;
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
        $this->afterCommit();
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 300, 900];
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return $this->channel === 'whatsapp'
            ? [(new RateLimited('whatsapp-notifications'))->releaseAfter(60)]
            : [];
    }

    public function failed(?Throwable $exception): void
    {
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
            return;
        }

        $receiptUrl = route('payment-batches.receipt', [
            'current_family' => $batch->family->slug,
            'payment_batch' => $batch->id,
        ]);

        if ($this->channel === 'mail') {
            $artifact = $artifacts->receipt($batch->family, $batch, $member);
            Mail::to($member->email)->send(new PaymentReceiptMail($batch, $artifact, $receiptUrl));

            return;
        }

        $phone = $member->routeNotificationForWhatsApp();

        if ($phone === null) {
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
        );

        throw_unless($result['success'], RuntimeException::class, 'WhatsApp payment receipt delivery failed: '.($result['error'] ?? 'Unknown error'));
    }
}
