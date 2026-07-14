<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\ReportArtifact;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ScheduledReportMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly ReportArtifact $artifact,
        public readonly string $downloadUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->artifact->type->label().' — '.$this->artifact->family->name);
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.reports.scheduled');
    }

    /** @return array<int, Attachment> */
    public function attachments(): array
    {
        return [
            Attachment::fromStorageDisk($this->artifact->disk, $this->artifact->path)
                ->as($this->artifact->filename)
                ->withMime($this->artifact->mime_type),
        ];
    }
}
