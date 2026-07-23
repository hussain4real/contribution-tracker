<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use Database\Factories\PaystackTransactionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

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
 * @property int|null $payment_batch_id
 * @property int|null $fee_expense_id
 * @property Carbon|null $verified_at
 * @property Carbon|null $allocated_at
 * @property Carbon|null $failed_at
 * @property string|null $failure_reason
 */
class PaystackTransaction extends Model
{
    /** @use HasFactory<PaystackTransactionFactory> */
    use HasFactory;

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
        'payment_batch_id',
        'fee_expense_id',
        'verified_at',
        'allocated_at',
        'failed_at',
        'failure_reason',
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
            'verified_at' => 'datetime',
            'allocated_at' => 'datetime',
            'failed_at' => 'datetime',
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

    /** @return BelongsTo<PaymentBatch, $this> */
    public function paymentBatch(): BelongsTo
    {
        return $this->belongsTo(PaymentBatch::class);
    }

    /** @return BelongsTo<Expense, $this> */
    public function feeExpense(): BelongsTo
    {
        return $this->belongsTo(Expense::class);
    }

    /** @return HasOne<ProviderSettlementItem, $this> */
    public function settlementItem(): HasOne
    {
        return $this->hasOne(ProviderSettlementItem::class);
    }

    // =========================================================================
    // Helper Methods
    // =========================================================================

    public function isPending(): bool
    {
        return in_array($this->status, [
            TransactionStatus::Pending,
            TransactionStatus::Initiated,
            TransactionStatus::Verified,
        ], true);
    }

    public function isSuccessful(): bool
    {
        return in_array($this->status, [TransactionStatus::Success, TransactionStatus::Allocated], true);
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
