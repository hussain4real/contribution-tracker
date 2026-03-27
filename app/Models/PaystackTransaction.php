<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaystackTransaction extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'reference',
        'user_id',
        'family_id',
        'type',
        'amount',
        'status',
        'paystack_response',
        'metadata',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => TransactionType::class,
            'status' => TransactionStatus::class,
            'amount' => 'integer',
            'paystack_response' => 'array',
            'metadata' => 'array',
        ];
    }

    // =========================================================================
    // Relationships
    // =========================================================================

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function family(): BelongsTo
    {
        return $this->belongsTo(Family::class);
    }

    // =========================================================================
    // Helper Methods
    // =========================================================================

    public function isPending(): bool
    {
        return $this->status === TransactionStatus::Pending;
    }

    public function isSuccessful(): bool
    {
        return $this->status === TransactionStatus::Success;
    }

    public function isFailed(): bool
    {
        return $this->status === TransactionStatus::Failed;
    }
}
