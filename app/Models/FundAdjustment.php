<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\CurrencyFormatter;
use Database\Factories\FundAdjustmentFactory;
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
 * @property Carbon $recorded_at
 * @property User|null $recorder
 * @property-read string $formatted_amount
 */
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
        'family_id',
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
     * The family this adjustment belongs to.
     *
     * @return BelongsTo<Family, $this>
     */
    public function family(): BelongsTo
    {
        return $this->belongsTo(Family::class);
    }

    /**
     * The user who recorded this adjustment.
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
     * @param  Builder<FundAdjustment>  $query
     * @return Builder<FundAdjustment>
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
        return CurrencyFormatter::format($this->amount, $this->family?->currency);
    }
}
