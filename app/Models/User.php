<?php

namespace App\Models;

use App\Enums\MemberCategory;
use App\Enums\Role;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\TwoFactorAuthenticatable;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable, TwoFactorAuthenticatable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'category',
        'archived_at',
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
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
            'role' => Role::class,
            'category' => MemberCategory::class,
            'archived_at' => 'datetime',
        ];
    }

    // =========================================================================
    // Relationships
    // =========================================================================

    /**
     * User has many contributions.
     */
    public function contributions(): HasMany
    {
        return $this->hasMany(Contribution::class);
    }

    /**
     * User has many payments they recorded.
     */
    public function recordedPayments(): HasMany
    {
        return $this->hasMany(Payment::class, 'recorded_by');
    }

    /**
     * User's own payments (through contributions).
     */
    public function payments(): HasManyThrough
    {
        return $this->hasManyThrough(Payment::class, Contribution::class);
    }

    // =========================================================================
    // Scopes
    // =========================================================================

    /**
     * Scope to only active (non-archived) users.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('archived_at');
    }

    /**
     * Scope to only archived users.
     */
    public function scopeArchived(Builder $query): Builder
    {
        return $query->whereNotNull('archived_at');
    }

    /**
     * Scope to users with Member role.
     */
    public function scopeMembers(Builder $query): Builder
    {
        return $query->where('role', Role::Member);
    }

    /**
     * Scope to users who can pay (have a category).
     */
    public function scopePayingMembers(Builder $query): Builder
    {
        return $query->whereNotNull('category');
    }

    /**
     * Scope to users with a specific category.
     */
    public function scopeWithCategory(Builder $query, MemberCategory $category): Builder
    {
        return $query->where('category', $category);
    }

    /**
     * Scope to Financial Secretaries only.
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
     * Check if user is a Super Admin.
     */
    public function isSuperAdmin(): bool
    {
        return $this->role === Role::SuperAdmin;
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
     * Check if user can view all members' details.
     */
    public function canViewAllMembers(): bool
    {
        return $this->role->canViewAllMembers();
    }

    /**
     * Get the monthly contribution amount in kobo for this user.
     */
    public function getMonthlyAmountInKobo(): ?int
    {
        return $this->category?->monthlyAmountInKobo();
    }
}
