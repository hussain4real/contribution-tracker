<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\WhatsAppMessageFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string|null $wa_message_id
 * @property string $direction
 * @property string $from
 * @property string $to
 * @property string $type
 * @property string|null $body
 * @property string|null $template_name
 * @property array<string, mixed>|null $payload
 * @property string $status
 * @property string|null $error_code
 * @property string|null $error_message
 * @property int|null $family_id
 * @property string|null $last_at
 * @property string|null $last_body
 * @property int $message_count
 * @property string|null $phone
 * @property int|null $user_id
 * @property User|null $user
 * @property Carbon|null $wa_timestamp
 * @property Carbon|null $read_at
 */
class WhatsAppMessage extends Model
{
    /** @use HasFactory<WhatsAppMessageFactory> */
    use HasFactory;

    protected $table = 'whatsapp_messages';

    protected $fillable = [
        'wa_message_id',
        'direction',
        'from',
        'to',
        'type',
        'body',
        'template_name',
        'payload',
        'status',
        'error_code',
        'error_message',
        'family_id',
        'user_id',
        'wa_timestamp',
        'read_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'wa_timestamp' => 'datetime',
            'read_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Family, $this>
     */
    public function family(): BelongsTo
    {
        return $this->belongsTo(Family::class);
    }

    /**
     * @param  Builder<WhatsAppMessage>  $query
     * @return Builder<WhatsAppMessage>
     */
    public function scopeInbound(Builder $query): Builder
    {
        return $query->where('direction', 'inbound');
    }

    /**
     * @param  Builder<WhatsAppMessage>  $query
     * @return Builder<WhatsAppMessage>
     */
    public function scopeOutbound(Builder $query): Builder
    {
        return $query->where('direction', 'outbound');
    }

    /**
     * @param  Builder<WhatsAppMessage>  $query
     * @return Builder<WhatsAppMessage>
     */
    public function scopeForFamily(Builder $query, int $familyId): Builder
    {
        return $query->where('family_id', $familyId);
    }

    /**
     * @param  Builder<WhatsAppMessage>  $query
     * @return Builder<WhatsAppMessage>
     */
    public function scopeForPhone(Builder $query, string $phone): Builder
    {
        return $query->where(function (Builder $q) use ($phone) {
            $q->where('from', $phone)->orWhere('to', $phone);
        });
    }

    public function isInbound(): bool
    {
        return $this->direction === 'inbound';
    }
}
