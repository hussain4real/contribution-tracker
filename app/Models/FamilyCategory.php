<?php

namespace App\Models;

use Database\Factories\FamilyCategoryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FamilyCategory extends Model
{
    /** @use HasFactory<FamilyCategoryFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'family_id',
        'name',
        'slug',
        'monthly_amount',
        'sort_order',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'monthly_amount' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    // =========================================================================
    // Relationships
    // =========================================================================

    /**
     * The family this category belongs to.
     */
    public function family(): BelongsTo
    {
        return $this->belongsTo(Family::class);
    }

    /**
     * Users assigned to this category.
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    // =========================================================================
    // Accessors
    // =========================================================================

    /**
     * Get the formatted monthly amount with currency symbol.
     */
    public function getFormattedAmountAttribute(): string
    {
        return '₦'.number_format($this->monthly_amount, 0);
    }

    /**
     * Get label with amount for display.
     */
    public function getLabelWithAmountAttribute(): string
    {
        return "{$this->name} ({$this->formatted_amount}/month)";
    }
}
