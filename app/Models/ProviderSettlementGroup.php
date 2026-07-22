<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ProviderSettlementGroupFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $family_id
 * @property int|null $bank_transaction_id
 * @property string $provider
 * @property string $reference
 * @property Carbon $settled_at
 * @property int $gross_amount
 * @property int $fee_amount
 * @property int $net_amount
 * @property int|null $bank_amount
 * @property int $difference
 * @property int|null $created_by
 * @property string|null $notes
 * @property-read Collection<int, ProviderSettlementItem> $items
 * @property-read Collection<int, ReconciliationLink> $reconciliationLinks
 */
class ProviderSettlementGroup extends Model
{
    /** @use HasFactory<ProviderSettlementGroupFactory> */
    use HasFactory;

    public const MORPH_TYPE = 'provider_settlement_group';

    /** @var list<string> */
    protected $fillable = [
        'family_id', 'bank_transaction_id', 'provider', 'reference', 'settled_at',
        'gross_amount', 'fee_amount', 'net_amount', 'bank_amount', 'difference', 'created_by', 'notes',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'settled_at' => 'date',
            'gross_amount' => 'integer',
            'fee_amount' => 'integer',
            'net_amount' => 'integer',
            'bank_amount' => 'integer',
            'difference' => 'integer',
        ];
    }

    /** @return BelongsTo<Family, $this> */
    public function family(): BelongsTo
    {
        return $this->belongsTo(Family::class);
    }

    /** @return BelongsTo<BankTransaction, $this> */
    public function bankTransaction(): BelongsTo
    {
        return $this->belongsTo(BankTransaction::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return HasMany<ProviderSettlementItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(ProviderSettlementItem::class);
    }

    /** @return MorphMany<ReconciliationLink, $this> */
    public function reconciliationLinks(): MorphMany
    {
        return $this->morphMany(ReconciliationLink::class, 'reconcilable');
    }
}
