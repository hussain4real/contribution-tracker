<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'contribution_id',
        'amount',
        'paid_at',
        'recorded_by',
        'notes',
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
            'paid_at' => 'datetime',
        ];
    }

    // =========================================================================
    // Relationships
    // =========================================================================

    /**
     * The contribution this payment is for.
     */
    public function contribution(): BelongsTo
    {
        return $this->belongsTo(Contribution::class);
    }

    /**
     * The user who recorded this payment (Financial Secretary or Super Admin).
     */
    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    // =========================================================================
    // Scopes
    // =========================================================================

    /**
     * Scope to payments for the current month.
     */
    public function scopeCurrentMonth(Builder $query): Builder
    {
        return $query->whereHas('contribution', function (Builder $q) {
            $q->currentMonth();
        });
    }

    /**
     * Scope to payments recorded by a specific user.
     */
    public function scopeRecordedBy(Builder $query, int|User $user): Builder
    {
        $userId = $user instanceof User ? $user->id : $user;

        return $query->where('recorded_by', $userId);
    }

    /**
     * Scope to payments made in a specific date range.
     */
    public function scopePaidBetween(Builder $query, $startDate, $endDate): Builder
    {
        return $query->whereBetween('paid_at', [$startDate, $endDate]);
    }

    /**
     * Scope to payments made today.
     */
    public function scopeToday(Builder $query): Builder
    {
        return $query->whereDate('paid_at', now()->toDateString());
    }

    /**
     * Scope to order by most recent first.
     */
    public function scopeLatestFirst(Builder $query): Builder
    {
        return $query->orderBy('paid_at', 'desc');
    }

    // =========================================================================
    // Accessors
    // =========================================================================

    /**
     * Get the formatted amount.
     */
    public function getFormattedAmountAttribute(): string
    {
        return '₦'.number_format($this->amount / 100, 2);
    }

    // =========================================================================
    // Helper Methods
    // =========================================================================

    /**
     * Get the member (user) who made this payment.
     */
    public function getMember(): ?User
    {
        return $this->contribution?->user;
    }

    /**
     * Get the contribution period label.
     */
    public function getPeriodLabel(): string
    {
        return $this->contribution?->period_label ?? '';
    }
}
