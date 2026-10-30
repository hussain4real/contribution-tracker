<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\PaymentReceiptDeliveryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $payment_batch_id
 * @property string $channel
 * @property string $status
 * @property string|null $provider_message_id
 * @property Carbon|null $attempted_at
 * @property Carbon|null $accepted_at
 * @property Carbon|null $failed_at
 * @property-read PaymentBatch $paymentBatch
 * @property-read WhatsAppMessage|null $whatsappMessage
 */
class PaymentReceiptDelivery extends Model
{
    /** @use HasFactory<PaymentReceiptDeliveryFactory> */
    use HasFactory;

    protected $fillable = [
        'payment_batch_id', 'channel', 'status', 'provider_message_id',
        'attempted_at', 'accepted_at', 'failed_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'attempted_at' => 'datetime',
            'accepted_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<PaymentBatch, $this> */
    public function paymentBatch(): BelongsTo
    {
        return $this->belongsTo(PaymentBatch::class);
    }

    /** @return BelongsTo<WhatsAppMessage, $this> */
    public function whatsappMessage(): BelongsTo
    {
        return $this->belongsTo(WhatsAppMessage::class, 'provider_message_id', 'wa_message_id');
    }

    public function displayStatus(): string
    {
        $providerStatus = $this->channel === 'whatsapp' ? $this->whatsappMessage?->status : null;

        return match ($providerStatus) {
            'delivered', 'read' => 'delivered',
            'failed' => 'failed',
            default => $this->status === 'failed' ? 'unconfirmed' : $this->status,
        };
    }

    public function failureReason(): ?string
    {
        $status = $this->displayStatus();
        if ($status === 'unconfirmed') {
            return 'The delivery attempt did not complete. Check the recipient details and provider before retrying.';
        }
        if ($status !== 'failed') {
            return null;
        }
        $code = $this->whatsappMessage?->error_code;

        return is_string($code) && preg_match('/^[0-9]{1,12}$/D', $code) === 1
            ? "WhatsApp reported delivery error {$code}."
            : 'WhatsApp reported that delivery failed.';
    }
}
