<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\FamilyFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property string $currency
 * @property int $due_day
 * @property Carbon|null $trial_ends_at
 * @property int|null $max_members
 * @property Carbon|null $created_at
 * @property string|null $bank_name
 * @property string|null $account_name
 * @property string|null $account_number
 * @property string|null $bank_code
 * @property Carbon|null $suspended_at
 * @property Carbon|null $archived_at
 * @property int|null $archived_by
 * @property string|null $archive_reason
 * @property Carbon|null $purge_after
 * @property Carbon|null $legal_hold_at
 * @property int|null $legal_hold_by
 * @property string|null $legal_hold_reason
 * @property string|null $paystack_subaccount_code
 * @property string|null $paystack_customer_code
 * @property string|null $paystack_subscription_code
 * @property string|null $paystack_subscription_email_token
 * @property string|null $subscription_status
 * @property Carbon|null $current_period_end
 * @property int|null $platform_plan_id
 * @property PlatformPlan|null $platformPlan
 * @property User|null $owner
 * @property Collection<int, User> $members
 * @property Collection<int, FamilyMembership> $memberships
 * @property Collection<int, FamilyCategory> $categories
 * @property int $members_count
 */
class Family extends Model
{
    /** @use HasFactory<FamilyFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'slug',
        'currency',
        'due_day',
        'bank_name',
        'account_name',
        'account_number',
        'bank_code',
        'created_by',
        'trial_ends_at',
        'max_members',
        'suspended_at',
        'archived_at',
        'archived_by',
        'archive_reason',
        'purge_after',
        'legal_hold_at',
        'legal_hold_by',
        'legal_hold_reason',
        'paystack_subaccount_code',
        'paystack_customer_code',
        'paystack_subscription_code',
        'paystack_subscription_email_token',
        'subscription_status',
        'current_period_end',
        'platform_plan_id',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'due_day' => 'integer',
            'trial_ends_at' => 'datetime',
            'max_members' => 'integer',
            'suspended_at' => 'datetime',
            'archived_at' => 'datetime',
            'purge_after' => 'datetime',
            'legal_hold_at' => 'datetime',
            'current_period_end' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    // =========================================================================
    // Helper Methods
    // =========================================================================

    /**
     * Check if the family is currently suspended.
     */
    public function isSuspended(): bool
    {
        return $this->suspended_at !== null;
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    public function isOnLegalHold(): bool
    {
        return $this->legal_hold_at !== null;
    }

    /**
     * Check if the family has an active Paystack subaccount.
     */
    public function hasPaystackSubaccount(): bool
    {
        return $this->paystack_subaccount_code !== null;
    }

    /**
     * Check if the family has an active subscription.
     */
    public function hasActiveSubscription(): bool
    {
        return $this->subscription_status === 'active';
    }

    /**
     * Check if the family has bank details configured.
     */
    public function hasBankDetails(): bool
    {
        return filled($this->bank_code) && filled($this->account_number);
    }

    // =========================================================================
    // Relationships
    // =========================================================================

    /**
     * The user who created this family.
     *
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * The platform plan this family is subscribed to.
     *
     * @return BelongsTo<PlatformPlan, $this>
     */
    public function platformPlan(): BelongsTo
    {
        return $this->belongsTo(PlatformPlan::class);
    }

    /**
     * Members belonging to this family.
     *
     * @return BelongsToMany<User, $this, FamilyMembership, 'pivot'>
     */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'family_members')
            ->using(FamilyMembership::class)
            ->withPivot([
                'id',
                'display_name',
                'role',
                'category',
                'family_category_id',
                'archived_at',
                'archived_by',
                'archive_reason',
            ])
            ->withTimestamps();
    }

    /**
     * Membership rows belonging to this family.
     *
     * @return HasMany<FamilyMembership, $this>
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(FamilyMembership::class);
    }

    /**
     * Categories defined for this family.
     *
     * @return HasMany<FamilyCategory, $this>
     */
    public function categories(): HasMany
    {
        return $this->hasMany(FamilyCategory::class);
    }

    /**
     * Contributions belonging to this family.
     *
     * @return HasMany<Contribution, $this>
     */
    public function contributions(): HasMany
    {
        return $this->hasMany(Contribution::class);
    }

    /** @return HasMany<PaymentBatch, $this> */
    public function paymentBatches(): HasMany
    {
        return $this->hasMany(PaymentBatch::class);
    }

    /**
     * Expenses belonging to this family.
     *
     * @return HasMany<Expense, $this>
     */
    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }

    /**
     * Fund adjustments belonging to this family.
     *
     * @return HasMany<FundAdjustment, $this>
     */
    public function fundAdjustments(): HasMany
    {
        return $this->hasMany(FundAdjustment::class);
    }

    /**
     * Invitations for this family.
     *
     * @return HasMany<FamilyInvitation, $this>
     */
    public function invitations(): HasMany
    {
        return $this->hasMany(FamilyInvitation::class);
    }

    /** @return HasMany<ReportArtifact, $this> */
    public function reportArtifacts(): HasMany
    {
        return $this->hasMany(ReportArtifact::class);
    }

    /** @return HasMany<ReportSchedule, $this> */
    public function reportSchedules(): HasMany
    {
        return $this->hasMany(ReportSchedule::class);
    }

    /** @return HasMany<ReconciliationImport, $this> */
    public function reconciliationImports(): HasMany
    {
        return $this->hasMany(ReconciliationImport::class);
    }

    /** @return HasMany<BankTransaction, $this> */
    public function bankTransactions(): HasMany
    {
        return $this->hasMany(BankTransaction::class);
    }

    /** @return HasMany<ReconciliationPeriod, $this> */
    public function reconciliationPeriods(): HasMany
    {
        return $this->hasMany(ReconciliationPeriod::class);
    }

    /** @return HasMany<ProviderSettlementGroup, $this> */
    public function providerSettlementGroups(): HasMany
    {
        return $this->hasMany(ProviderSettlementGroup::class);
    }

    protected static function booted(): void
    {
        static::deleting(function (Family $family): void {
            if (
                $family->contributions()->exists()
                || $family->paymentBatches()->exists()
                || $family->expenses()->exists()
                || $family->fundAdjustments()->exists()
                || $family->reconciliationImports()->exists()
                || $family->reconciliationPeriods()->exists()
                || $family->providerSettlementGroups()->exists()
            ) {
                throw new LogicException('A family with financial history cannot be deleted.');
            }
        });
    }
}
