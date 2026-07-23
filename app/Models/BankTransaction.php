<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BankTransactionDirection;
use App\Enums\ReconciliationStatus;
use Database\Factories\BankTransactionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * @property int $id
 * @property int $family_id
 * @property int $reconciliation_import_id
 * @property int $row_number
 * @property BankTransactionDirection $direction
 * @property Carbon $transacted_at
 * @property int $amount
 * @property string|null $reference
 * @property string|null $description
 * @property string|null $source_account
 * @property array<string, string|null> $raw_data
 * @property string $row_fingerprint
 * @property ReconciliationStatus $status
 * @property string|null $ignored_reason
 * @property string|null $disputed_reason
 * @property-read Family $family
 * @property-read ReconciliationImport $import
 * @property-read Collection<int, ReconciliationLink> $links
 */
class BankTransaction extends Model
{
    /** @use HasFactory<BankTransactionFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'family_id', 'reconciliation_import_id', 'row_number', 'direction', 'transacted_at',
        'amount', 'reference', 'description', 'source_account', 'raw_data', 'row_fingerprint',
        'status', 'ignored_reason', 'disputed_reason',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'row_number' => 'integer',
            'direction' => BankTransactionDirection::class,
            'transacted_at' => 'date',
            'amount' => 'integer',
            'raw_data' => 'array',
            'status' => ReconciliationStatus::class,
        ];
    }

    /** @return BelongsTo<Family, $this> */
    public function family(): BelongsTo
    {
        return $this->belongsTo(Family::class);
    }

    /** @return BelongsTo<ReconciliationImport, $this> */
    public function import(): BelongsTo
    {
        return $this->belongsTo(ReconciliationImport::class, 'reconciliation_import_id');
    }

    /** @return HasMany<ReconciliationLink, $this> */
    public function links(): HasMany
    {
        return $this->hasMany(ReconciliationLink::class);
    }

    /**
     * @param  Builder<BankTransaction>  $query
     * @return Builder<BankTransaction>
     */
    public function scopeForQueue(Builder $query, ReconciliationStatus $status): Builder
    {
        return $query->where('status', $status);
    }

    public function linkedAmount(): int
    {
        $amount = $this->relationLoaded('links') ? $this->links->sum('amount') : $this->links()->sum('amount');

        return is_numeric($amount) ? (int) $amount : 0;
    }

    public function remainingAmount(): int
    {
        return max(0, $this->amount - $this->linkedAmount());
    }

    protected static function booted(): void
    {
        static::updating(function (BankTransaction $transaction): void {
            $allowed = ['status', 'ignored_reason', 'disputed_reason', 'updated_at'];

            if (array_diff(array_keys($transaction->getDirty()), $allowed) !== []) {
                throw new LogicException('Normalized bank transaction data is immutable.');
            }
        });
        static::deleting(fn (): never => throw new LogicException('Imported bank transactions cannot be deleted.'));
    }
}
