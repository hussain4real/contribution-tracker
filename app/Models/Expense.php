<?php

namespace App\Models;

use Database\Factories\ExpenseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
     * The user who recorded this expense.
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
     */
    public function scopeLatestFirst(Builder $query): Builder
    {
        return $query->orderBy('spent_at', 'desc');
    }

    /**
     * Scope to expenses in a specific date range.
     */
    public function scopeSpentBetween(Builder $query, $startDate, $endDate): Builder
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
        return '₦'.number_format($this->amount, 2);
    }
}
