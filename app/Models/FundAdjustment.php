<?php

namespace App\Models;

use Database\Factories\FundAdjustmentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FundAdjustment extends Model
{
    /** @use HasFactory<FundAdjustmentFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'amount',
        'description',
        'recorded_at',
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
            'recorded_at' => 'datetime',
        ];
    }

    // =========================================================================
    // Relationships
    // =========================================================================

    /**
     * The user who recorded this adjustment.
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
        return $query->orderBy('recorded_at', 'desc');
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
