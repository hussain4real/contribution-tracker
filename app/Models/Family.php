<?php

namespace App\Models;

use Database\Factories\FamilyFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
        'created_by',
        'plan',
        'trial_ends_at',
        'max_members',
        'suspended_at',
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
        ];
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

    // =========================================================================
    // Relationships
    // =========================================================================

    /**
     * The user who created this family.
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Members belonging to this family.
     */
    public function members(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * Categories defined for this family.
     */
    public function categories(): HasMany
    {
        return $this->hasMany(FamilyCategory::class);
    }

    /**
     * Contributions belonging to this family.
     */
    public function contributions(): HasMany
    {
        return $this->hasMany(Contribution::class);
    }

    /**
     * Expenses belonging to this family.
     */
    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }

    /**
     * Fund adjustments belonging to this family.
     */
    public function fundAdjustments(): HasMany
    {
        return $this->hasMany(FundAdjustment::class);
    }

    /**
     * Invitations for this family.
     */
    public function invitations(): HasMany
    {
        return $this->hasMany(FamilyInvitation::class);
    }
}
