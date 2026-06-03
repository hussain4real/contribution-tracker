<?php

declare(strict_types=1);

namespace App\Enums;

enum InvitationDeliveryMethod: string
{
    case Email = 'email';
    case WhatsApp = 'whatsapp';

    /**
     * Get the human-readable label for the delivery method.
     */
    public function label(): string
    {
        return match ($this) {
            self::Email => 'Email',
            self::WhatsApp => 'WhatsApp',
        };
    }
}
