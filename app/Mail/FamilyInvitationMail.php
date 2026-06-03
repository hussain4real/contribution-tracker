<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\Family;
use App\Models\FamilyInvitation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class FamilyInvitationMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /**
     * Create a new message instance.
     */
    public function __construct(
        public FamilyInvitation $invitation,
        public string $acceptUrl,
    ) {}

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        $family = $this->invitation->family;
        $familyName = $family instanceof Family ? $family->name : 'your family';

        return new Envelope(
            subject: "You've been invited to join {$familyName}",
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            markdown: 'mail.family-invitation',
        );
    }
}
