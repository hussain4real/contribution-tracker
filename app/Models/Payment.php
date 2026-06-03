<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\PaymentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $contribution_id
 * @property int $amount
 * @property Carbon|null $created_at
 * @property Carbon $paid_at
 * @property string|null $notes
 * @property Contribution|null $contribution
 * @property User|null $recorder
 * @property-read string $formatted_amount
 */
class Payment extends Model
{
    /** @use HasFactory<PaymentFactory> */
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
     *
     * @return BelongsTo<Contribution, $this>
     */
    public function contribution(): BelongsTo
    {
        return $this->belongsTo(Contribution::class);
    }

    /**
     * The user who recorded this payment (Financial Secretary or Admin).
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
     * Scope to payments for the current month.
     *
     * @param  Builder<Payment>  $query
     * @return Builder<Payment>
     */
    public function scopeCurrentMonth(Builder $query): Builder
    {
        return $query->whereIn(
            'contribution_id',
            Contribution::query()
                ->currentMonth()
                ->select('id'),
        );
    }

    /**
     * Scope to payments recorded by a specific user.
     *
     * @param  Builder<Payment>  $query
     * @return Builder<Payment>
     */
    public function scopeRecordedBy(Builder $query, int|User $user): Builder
    {
        $userId = $user instanceof User ? $user->id : $user;

        return $query->where('recorded_by', $userId);
    }

    /**
     * Scope to payments made in a specific date range.
     *
     * @param  Builder<Payment>  $query
     * @return Builder<Payment>
     */
    public function scopePaidBetween(Builder $query, mixed $startDate, mixed $endDate): Builder
    {
        return $query->whereBetween('paid_at', [$startDate, $endDate]);
    }

    /**
     * Scope to payments made today.
     *
     * @param  Builder<Payment>  $query
     * @return Builder<Payment>
     */
    public function scopeToday(Builder $query): Builder
    {
        return $query->whereDate('paid_at', now()->toDateString());
    }

    /**
     * Scope to order by most recent first.
     *
     * @param  Builder<Payment>  $query
     * @return Builder<Payment>
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
        return '₦'.number_format($this->amount, 2);
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
        $contribution = $this->contribution;

        return $contribution instanceof Contribution ? $contribution->period_label : '';
    }
}
