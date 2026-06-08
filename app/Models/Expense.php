<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\CurrencyFormatter;
use Database\Factories\ExpenseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $family_id
 * @property int $amount
 * @property Carbon|null $created_at
 * @property string $description
 * @property Carbon $spent_at
 * @property User|null $recorder
 * @property-read string $formatted_amount
 */
class Expense extends Model
{
    /** @use HasFactory<ExpenseFactory> */
    use HasFactory;

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
    ];

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
}
