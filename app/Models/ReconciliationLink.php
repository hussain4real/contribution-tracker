<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ReconciliationLinkFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use LogicException;

/**
 * @property int $id
 * @property int $family_id
 * @property int $bank_transaction_id
 * @property string $reconcilable_type
 * @property int $reconcilable_id
 * @property int $amount
 * @property int|null $created_by
 * @property string|null $notes
 * @property-read Family $family
 * @property-read BankTransaction $bankTransaction
 * @property-read Model $reconcilable
 * @property-read User|null $creator
 */
class ReconciliationLink extends Model
{
    /** @use HasFactory<ReconciliationLinkFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'family_id', 'bank_transaction_id', 'reconcilable_type', 'reconcilable_id',
        'amount', 'created_by', 'notes',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['amount' => 'integer'];
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

    /** @return MorphTo<Model, $this> */
    public function reconcilable(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new LogicException('Reconciliation links are immutable; remove and recreate the link.'));
    }
}
