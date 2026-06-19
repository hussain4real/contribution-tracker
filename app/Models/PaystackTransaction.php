<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $amount
 * @property int|null $actual_fee_kobo
 * @property int|null $estimated_fee_kobo
 * @property int $family_id
 * @property string $fee_policy
 * @property int|null $gross_amount_kobo
 * @property array<string, mixed>|null $metadata
 * @property array<string, mixed>|null $paystack_response
 * @property string $reference
 * @property int|null $settled_amount_kobo
 * @property TransactionStatus $status
 * @property TransactionType $type
 * @property int $user_id
 */
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
        'gross_amount_kobo',
        'estimated_fee_kobo',
        'actual_fee_kobo',
        'settled_amount_kobo',
        'fee_policy',
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
            'gross_amount_kobo' => 'integer',
            'estimated_fee_kobo' => 'integer',
            'actual_fee_kobo' => 'integer',
            'settled_amount_kobo' => 'integer',
            'paystack_response' => 'array',
            'metadata' => 'array',
        ];
    }

    // =========================================================================
    // Relationships
    // =========================================================================

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

    public function expectedGrossAmountKobo(): int
    {
        return $this->gross_amount_kobo ?? ($this->amount * 100);
    }

    public function contributionAmountKobo(): int
    {
        return $this->amount * 100;
    }
}
