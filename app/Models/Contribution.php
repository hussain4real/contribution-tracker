<?php

namespace App\Models;

use App\Enums\PaymentStatus;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Contribution extends Model
{
    use HasFactory;

    /**
     * The due day of the month for all contributions.
     */
    public const DUE_DAY = 28;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'year',
        'month',
        'expected_amount',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'month' => 'integer',
            'expected_amount' => 'integer',
        ];
    }

    // =========================================================================
    // Relationships
    // =========================================================================

    /**
     * The user this contribution belongs to.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Payments made toward this contribution.
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    // =========================================================================
    // Scopes
    // =========================================================================

    /**
     * Scope to contributions for a specific month and year.
     */
    public function scopeForMonth(Builder $query, int $year, int $month): Builder
    {
        return $query->where('year', $year)->where('month', $month);
    }

    /**
     * Scope to contributions for the current month.
     */
    public function scopeCurrentMonth(Builder $query): Builder
    {
        return $query->forMonth(now()->year, now()->month);
    }

    /**
     * Scope to contributions for a specific user.
     */
    public function scopeForUser(Builder $query, int|User $user): Builder
    {
        $userId = $user instanceof User ? $user->id : $user;

        return $query->where('user_id', $userId);
    }

    /**
     * Scope to contributions that are overdue.
     */
    public function scopeOverdue(Builder $query): Builder
    {
        $today = now();

        return $query->where(function (Builder $q) use ($today) {
            // Past years are definitely overdue
            $q->where('year', '<', $today->year)
                // Or same year but past month
                ->orWhere(function (Builder $q2) use ($today) {
                    $q2->where('year', $today->year)
                        ->where('month', '<', $today->month);
                })
                // Or current month but past due date
                ->orWhere(function (Builder $q2) use ($today) {
                    $q2->where('year', $today->year)
                        ->where('month', $today->month)
                        ->where($today->day > self::DUE_DAY, true);
                });
        });
    }

    /**
     * Scope to incomplete contributions (not fully paid).
     */
    public function scopeIncomplete(Builder $query): Builder
    {
        return $query->whereRaw('(
            SELECT COALESCE(SUM(amount), 0) FROM payments WHERE payments.contribution_id = contributions.id
        ) < expected_amount');
    }

    /**
     * Scope to contributions ordered by oldest first (for balance-first rule).
     */
    public function scopeOldestFirst(Builder $query): Builder
    {
        return $query->orderBy('year')->orderBy('month');
    }

    // =========================================================================
    // Accessors
    // =========================================================================

    /**
     * Get the total amount paid toward this contribution.
     */
    public function getTotalPaidAttribute(): int
    {
        return (int) $this->payments()->sum('amount');
    }

    /**
     * Get the remaining balance for this contribution.
     */
    public function getBalanceAttribute(): int
    {
        return max(0, $this->expected_amount - $this->total_paid);
    }

    /**
     * Get the payment status for this contribution.
     */
    public function getStatusAttribute(): PaymentStatus
    {
        $totalPaid = $this->total_paid;

        if ($totalPaid >= $this->expected_amount) {
            return PaymentStatus::Paid;
        }

        if ($totalPaid > 0) {
            // Check if overdue
            if ($this->isOverdue()) {
                return PaymentStatus::Overdue;
            }

            return PaymentStatus::Partial;
        }

        // Nothing paid
        if ($this->isOverdue()) {
            return PaymentStatus::Overdue;
        }

        return PaymentStatus::Unpaid;
    }

    /**
     * Get the due date for this contribution.
     */
    public function getDueDateAttribute(): Carbon
    {
        return Carbon::createFromDate($this->year, $this->month, self::DUE_DAY);
    }

    /**
     * Get the period label (e.g., "January 2025").
     */
    public function getPeriodLabelAttribute(): string
    {
        return Carbon::createFromDate($this->year, $this->month, 1)->format('F Y');
    }

    // =========================================================================
    // Helper Methods
    // =========================================================================

    /**
     * Check if this contribution is overdue.
     */
    public function isOverdue(): bool
    {
        return now()->greaterThan($this->due_date);
    }

    /**
     * Check if this contribution is fully paid.
     */
    public function isPaid(): bool
    {
        return $this->total_paid >= $this->expected_amount;
    }

    /**
     * Check if this contribution has partial payment.
     */
    public function isPartiallyPaid(): bool
    {
        $totalPaid = $this->total_paid;

        return $totalPaid > 0 && $totalPaid < $this->expected_amount;
    }

    /**
     * Check if this contribution can accept more payments.
     */
    public function canAcceptPayment(): bool
    {
        return ! $this->isPaid();
    }

    /**
     * Get the formatted expected amount.
     */
    public function formattedExpectedAmount(): string
    {
        return '₦'.number_format($this->expected_amount / 100, 2);
    }

    /**
     * Get the formatted total paid.
     */
    public function formattedTotalPaid(): string
    {
        return '₦'.number_format($this->total_paid / 100, 2);
    }

    /**
     * Get the formatted balance.
     */
    public function formattedBalance(): string
    {
        return '₦'.number_format($this->balance / 100, 2);
    }
}
