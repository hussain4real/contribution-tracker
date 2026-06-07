<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PaymentStatus;
use Carbon\Carbon;
use Database\Factories\ContributionFactory;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $family_id
 * @property int $user_id
 * @property int $expected_amount
 * @property int $month
 * @property int $year
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property Carbon $due_date
 * @property PaymentStatus $status
 * @property string $period_label
 * @property Family|null $family
 * @property User|null $user
 * @property Collection<int, Payment> $payments
 * @property-read int $total_paid
 * @property-read int $balance
 */
class Contribution extends Model
{
    /** @use HasFactory<ContributionFactory> */
    use HasFactory;

    /**
     * The due day of the month for all contributions.
     */
    public const DUE_DAY = 28;

    /**
     * Resolve the configured due day within the target month.
     */
    public static function dueDateForMonth(int $year, int $month, int $dueDay): Carbon
    {
        $date = Carbon::createFromDate($year, $month, 1);

        return $date->setDay(max(1, min($dueDay, $date->daysInMonth)));
    }

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'family_id',
        'user_id',
        'year',
        'month',
        'expected_amount',
        'due_date',
        'reminder_sent_at',
        'follow_up_sent_at',
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
            'due_date' => 'date',
            'reminder_sent_at' => 'datetime',
            'follow_up_sent_at' => 'datetime',
        ];
    }

    // =========================================================================
    // Relationships
    // =========================================================================

    /**
     * The family this contribution belongs to.
     *
     * @return BelongsTo<Family, $this>
     */
    public function family(): BelongsTo
    {
        return $this->belongsTo(Family::class);
    }

    /**
     * The user this contribution belongs to.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Payments made toward this contribution.
     *
     * @return HasMany<Payment, $this>
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
     *
     * @param  Builder<Contribution>  $query
     * @return Builder<Contribution>
     */
    public function scopeForMonth(Builder $query, int $year, int $month): Builder
    {
        return $query->where('year', $year)->where('month', $month);
    }

    /**
     * Scope to contributions for the current month.
     *
     * @param  Builder<Contribution>  $query
     * @return Builder<Contribution>
     */
    public function scopeCurrentMonth(Builder $query): Builder
    {
        return $query->forMonth(now()->year, now()->month);
    }

    /**
     * Scope to contributions for a specific user.
     *
     * @param  Builder<Contribution>  $query
     * @return Builder<Contribution>
     */
    public function scopeForUser(Builder $query, int|User $user): Builder
    {
        $userId = $user instanceof User ? $user->id : $user;

        return $query->where('user_id', $userId);
    }

    /**
     * Scope to contributions that are overdue.
     *
     * @param  Builder<Contribution>  $query
     * @return Builder<Contribution>
     */
    public function scopeOverdue(Builder $query): Builder
    {
        return $query->where('due_date', '<', now()->startOfDay());
    }

    /**
     * Scope to incomplete contributions (not fully paid).
     *
     * @param  Builder<Contribution>  $query
     * @return Builder<Contribution>
     */
    public function scopeIncomplete(Builder $query): Builder
    {
        return $query->whereRaw('(
            SELECT COALESCE(SUM(amount), 0) FROM payments WHERE payments.contribution_id = contributions.id
        ) < expected_amount');
    }

    /**
     * Scope to contributions ordered by oldest first (for balance-first rule).
     *
     * @param  Builder<Contribution>  $query
     * @return Builder<Contribution>
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
     *
     * Uses the eager-loaded collection when available, otherwise
     * falls back to a database aggregate query.
     */
    public function getTotalPaidAttribute(): int
    {
        if ($this->relationLoaded('payments')) {
            return (int) $this->payments->sum(fn (Payment $payment): int => $payment->amount);
        }

        $total = $this->payments()->sum('amount');

        return (int) $total;
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

        if ($this->isOverdue()) {
            return PaymentStatus::Overdue;
        }

        return $totalPaid > 0 ? PaymentStatus::Partial : PaymentStatus::Unpaid;
    }

    /**
     * Get the due date for this contribution.
     */
    public function getDueDateAttribute(): Carbon
    {
        $dueDate = $this->attributes['due_date'] ?? null;

        if ($dueDate instanceof DateTimeInterface || is_string($dueDate) || is_int($dueDate) || is_float($dueDate)) {
            return Carbon::parse($dueDate);
        }

        return self::dueDateForMonth($this->year, $this->month, self::DUE_DAY);
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
        return '₦'.number_format($this->expected_amount, 2);
    }

    /**
     * Get the formatted total paid.
     */
    public function formattedTotalPaid(): string
    {
        return '₦'.number_format($this->total_paid, 2);
    }

    /**
     * Get the formatted balance.
     */
    public function formattedBalance(): string
    {
        return '₦'.number_format($this->balance, 2);
    }
}
