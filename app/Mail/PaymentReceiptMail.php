<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\PaymentBatch;
use App\Models\ReportArtifact;
use App\Support\CurrencyFormatter;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PaymentReceiptMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly PaymentBatch $batch,
        public readonly ReportArtifact $artifact,
        public readonly string $receiptUrl,
    ) {}

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(subject: "Payment receipt #{$this->batch->receipt_number} — {$this->batch->family->name}");
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            markdown: 'mail.payment-receipt',
            with: ['amount' => CurrencyFormatter::format($this->batch->total_amount, $this->batch->family->currency)],
        );
    }

    /**
     * Get the attachments for the message.
     *
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        return [
            Attachment::fromStorageDisk($this->artifact->disk, $this->artifact->path)
                ->as($this->artifact->filename)
                ->withMime($this->artifact->mime_type),
        ];
    }
}
