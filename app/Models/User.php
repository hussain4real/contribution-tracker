<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\MemberCategory;
use App\Enums\Role;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Passport\Contracts\OAuthenticatable;
use Laravel\Passport\HasApiTokens;
use NotificationChannels\WebPush\HasPushSubscriptions;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property Carbon|null $created_at
 * @property Carbon|null $archived_at
 * @property Carbon|null $email_verified_at
 * @property Carbon|null $must_change_password_at
 * @property int|null $family_id
 * @property bool $is_super_admin
 * @property MemberCategory|null $category
 * @property FamilyCategory|null $familyCategory
 * @property Family|null $family
 * @property Role $role
 * @property string|null $whatsapp_phone
 * @property Carbon|null $whatsapp_verified_at
 * @property Collection<int, Contribution> $contributions
 */
class User extends Authenticatable implements MustVerifyEmail, OAuthenticatable, PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasPushSubscriptions, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'must_change_password_at',
        'role',
        'category',
        'family_id',
        'family_category_id',
        'is_super_admin',
        'archived_at',
        'paystack_customer_code',
        'whatsapp_phone',
        'whatsapp_verified_at',
        'last_seen_changelog_release_id',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'must_change_password_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
            'role' => Role::class,
            'category' => MemberCategory::class,
            'is_super_admin' => 'boolean',
            'archived_at' => 'datetime',
            'whatsapp_verified_at' => 'datetime',
            'last_seen_changelog_release_id' => 'integer',
        ];
    }

    // =========================================================================
    // Relationships
    // =========================================================================

    /**
     * The family this user belongs to.
     *
     * @return BelongsTo<Family, $this>
     */
    public function family(): BelongsTo
    {
        return $this->belongsTo(Family::class);
    }

    /**
     * The family category (contribution tier) for this user.
     *
     * @return BelongsTo<FamilyCategory, $this>
     */
    public function familyCategory(): BelongsTo
    {
        return $this->belongsTo(FamilyCategory::class);
    }

    /**
     * User has many contributions.
     *
     * @return HasMany<Contribution, $this>
     */
    public function contributions(): HasMany
    {
        return $this->hasMany(Contribution::class);
    }

    /**
     * User has many payments they recorded.
     *
     * @return HasMany<Payment, $this>
     */
    public function recordedPayments(): HasMany
    {
        return $this->hasMany(Payment::class, 'recorded_by');
    }

    /**
     * User's own payments (through contributions).
     *
     * @return HasManyThrough<Payment, Contribution, $this>
     */
    public function payments(): HasManyThrough
    {
        return $this->hasManyThrough(Payment::class, Contribution::class);
    }

    public function hasWebPushSubscription(): bool
    {
        return Cache::remember(
            $this->webPushSubscriptionCacheKey(),
            now()->addMinutes(5),
            fn (): bool => $this->pushSubscriptions()->exists(),
        );
    }

    public function forgetWebPushSubscriptionCache(): void
    {
        Cache::forget($this->webPushSubscriptionCacheKey());
    }

    private function webPushSubscriptionCacheKey(): string
    {
        return "users.{$this->id}.web_push_subscribed";
    }

    // =========================================================================
    // Scopes
    // =========================================================================

    /**
     * Scope to only active (non-archived) users.
     *
     * @param  Builder<User>  $query
     * @return Builder<User>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('archived_at');
    }

    /**
     * Scope to only archived users.
     *
     * @param  Builder<User>  $query
     * @return Builder<User>
     */
    public function scopeArchived(Builder $query): Builder
    {
        return $query->whereNotNull('archived_at');
    }

    /**
     * Scope to users with Member role.
     *
     * @param  Builder<User>  $query
     * @return Builder<User>
     */
    public function scopeMembers(Builder $query): Builder
    {
        return $query->where('role', Role::Member);
    }

    /**
     * Scope to users who can pay (have a contribution category).
     *
     * @param  Builder<User>  $query
     * @return Builder<User>
     */
    public function scopePayingMembers(Builder $query): Builder
    {
        return $query->where(function (Builder $query): void {
            $query->whereNotNull('category')
                ->orWhereNotNull('family_category_id');
        });
    }

    /**
     * Scope to users with a specific category.
     *
     * @param  Builder<User>  $query
     * @return Builder<User>
     */
    public function scopeWithCategory(Builder $query, MemberCategory $category): Builder
    {
        return $query->where('category', $category);
    }

    /**
     * Scope to Financial Secretaries only.
     *
     * @param  Builder<User>  $query
     * @return Builder<User>
     */
    public function scopeFinancialSecretaries(Builder $query): Builder
    {
        return $query->where('role', Role::FinancialSecretary);
    }

    // =========================================================================
    // Helper Methods
    // =========================================================================

    /**
     * Check if user is archived.
     */
    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    /**
     * Check if the user must replace a temporary onboarding password.
     */
    public function mustChangePassword(): bool
    {
        return $this->must_change_password_at !== null;
    }

    /**
     * Check if user is a platform Super Admin.
     */
    public function isSuperAdmin(): bool
    {
        return $this->is_super_admin === true;
    }

    /**
     * Check if user is a Family Admin.
     */
    public function isAdmin(): bool
    {
        return $this->role === Role::Admin;
    }

    /**
     * Check if user is a Financial Secretary.
     */
    public function isFinancialSecretary(): bool
    {
        return $this->role === Role::FinancialSecretary;
    }

    /**
     * Check if user is a regular Member.
     */
    public function isMember(): bool
    {
        return $this->role === Role::Member;
    }

    /**
     * Check if user can record payments.
     */
    public function canRecordPayments(): bool
    {
        return $this->role->canRecordPayments();
    }

    /**
     * Check if user can manage members.
     */
    public function canManageMembers(): bool
    {
        return $this->role->canManageMembers();
    }

    /**
     * Check if user can add or invite ordinary members.
     */
    public function canAddMembers(): bool
    {
        return $this->role->canAddMembers();
    }

    /**
     * Check if user can assign privileged roles.
     */
    public function canManageRoles(): bool
    {
        return $this->role->canManageRoles();
    }

    /**
     * Check if user can view all members' details.
     */
    public function canViewAllMembers(): bool
    {
        return $this->role->canViewAllMembers();
    }

    /**
     * Get the monthly contribution amount for this user.
     */
    public function getMonthlyAmount(): ?int
    {
        if ($this->familyCategory !== null) {
            return $this->familyCategory->monthly_amount;
        }

        return $this->category?->monthlyAmount();
    }

    /**
     * Check if the user has a verified WhatsApp number.
     */
    public function hasVerifiedWhatsApp(): bool
    {
        return $this->whatsapp_phone !== null && $this->whatsapp_verified_at !== null;
    }

    /**
     * Route notifications for the WhatsApp channel.
     *
     * Returns the verified WhatsApp phone number, or null if the user
     * has not verified their WhatsApp number. Returning null causes the
     * channel to skip this notifiable silently.
     */
    public function routeNotificationForWhatsApp(): ?string
    {
        return $this->hasVerifiedWhatsApp() ? $this->whatsapp_phone : null;
    }
}
