<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\CurrencyFormatter;
use Database\Factories\ExpenseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * @property int|null $expense_request_id
 * @property-read Collection<int, ExpenseDocument> $documents
 * @property int|null $expense_category_id
 * @property-read ExpenseCategory|null $category
 * @property int $id
 * @property int $family_id
 * @property int $amount
 * @property Carbon|null $created_at
 * @property string $description
 * @property string|null $idempotency_key
 * @property int|null $recorded_by
 * @property Carbon $spent_at
 * @property User|null $recorder
 * @property-read FinancialReversal|null $reversal
 * @property-read string $formatted_amount
 */
class Expense extends Model
{
    /** @use HasFactory<ExpenseFactory> */
    use HasFactory;

    public const MORPH_TYPE = 'expense';

    private ?int $providerFeeTransactionId = null;

    public static function postPaystackFee(PaystackTransaction $transaction, int $amount, string $spentAt): self
    {
        $expense = new self([
            'family_id' => $transaction->family_id,
            'amount' => $amount,
            'description' => "Paystack processing fee shortfall for transaction {$transaction->reference}",
            'spent_at' => $spentAt,
            'recorded_by' => $transaction->user_id,
            'idempotency_key' => 'paystack-fee:'.$transaction->id,
        ]);
        $expense->providerFeeTransactionId = $transaction->id;
        $expense->save();

        return $expense;
    }

    public function providerFeeTransactionId(): ?int
    {
        return $this->providerFeeTransactionId;
    }

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'family_id',
        'amount',
        'description',
        'spent_at',
        'recorded_by',
        'idempotency_key',
        'expense_request_id',
        'expense_category_id',
    ];

    /** @return BelongsTo<ExpenseCategory, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'expense_category_id');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'spent_at' => 'datetime',
        ];
    }

    // =========================================================================
    // Relationships
    // =========================================================================

    /**
     * The family this expense belongs to.
     *
     * @return BelongsTo<Family, $this>
     */
    public function family(): BelongsTo
    {
        return $this->belongsTo(Family::class);
    }

    /**
     * The user who recorded this expense.
     *
     * @return BelongsTo<User, $this>
     */
    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    /** @return MorphOne<FinancialReversal, $this> */
    public function reversal(): MorphOne
    {
        return $this->morphOne(FinancialReversal::class, 'reversible');
    }

    /** @return MorphMany<ReconciliationLink, $this> */
    public function reconciliationLinks(): MorphMany
    {
        return $this->morphMany(ReconciliationLink::class, 'reconcilable');
    }

    /** @return HasMany<ExpenseDocument, $this> */
    public function documents(): HasMany
    {
        return $this->hasMany(ExpenseDocument::class);
    }

    // =========================================================================
    // Scopes
    // =========================================================================

    /**
     * Scope to order by most recent first.
     *
     * @param  Builder<Expense>  $query
     * @return Builder<Expense>
     */
    public function scopeLatestFirst(Builder $query): Builder
    {
        return $query->orderBy('spent_at', 'desc');
    }

    /**
     * Scope to expenses in a specific date range.
     *
     * @param  Builder<Expense>  $query
     * @return Builder<Expense>
     */
    public function scopeSpentBetween(Builder $query, mixed $startDate, mixed $endDate): Builder
    {
        return $query->whereBetween('spent_at', [$startDate, $endDate]);
    }

    /**
     * @param  Builder<Expense>  $query
     * @return Builder<Expense>
     */
    public function scopeEffective(Builder $query): Builder
    {
        return $query->whereDoesntHave('reversal');
    }

    public function isReversed(): bool
    {
        return $this->relationLoaded('reversal')
            ? $this->reversal instanceof FinancialReversal
            : $this->reversal()->exists();
    }

    // =========================================================================
    // Accessors
    // =========================================================================

    /**
     * Get the formatted amount.
     */
    public function getFormattedAmountAttribute(): string
    {
        return CurrencyFormatter::format($this->amount, $this->family?->currency);
    }

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new LogicException('Posted expenses are immutable.'));
        static::deleting(fn (): never => throw new LogicException('Posted expenses cannot be deleted.'));
    }
}
