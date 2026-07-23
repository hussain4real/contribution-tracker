<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ProviderSettlementItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $provider_settlement_group_id
 * @property int $paystack_transaction_id
 * @property int $payment_batch_id
 * @property int $gross_amount
 * @property int $fee_amount
 * @property int $net_amount
 */
class ProviderSettlementItem extends Model
{
    /** @use HasFactory<ProviderSettlementItemFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'provider_settlement_group_id', 'paystack_transaction_id', 'payment_batch_id',
        'gross_amount', 'fee_amount', 'net_amount',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['gross_amount' => 'integer', 'fee_amount' => 'integer', 'net_amount' => 'integer'];
    }

    /** @return BelongsTo<ProviderSettlementGroup, $this> */
    public function group(): BelongsTo
    {
        return $this->belongsTo(ProviderSettlementGroup::class, 'provider_settlement_group_id');
    }

    /** @return BelongsTo<PaystackTransaction, $this> */
    public function paystackTransaction(): BelongsTo
    {
        return $this->belongsTo(PaystackTransaction::class);
    }

    /** @return BelongsTo<PaymentBatch, $this> */
    public function paymentBatch(): BelongsTo
    {
        return $this->belongsTo(PaymentBatch::class);
    }
}
