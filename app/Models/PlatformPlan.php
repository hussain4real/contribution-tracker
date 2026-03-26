<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PlatformPlan extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'slug',
        'price',
        'max_members',
        'paystack_plan_code',
        'features',
        'is_active',
        'sort_order',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price' => 'integer',
            'max_members' => 'integer',
            'features' => 'array',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    // =========================================================================
    // Relationships
    // =========================================================================

    public function families(): HasMany
    {
        return $this->hasMany(Family::class, 'platform_plan_id');
    }

    // =========================================================================
    // Helper Methods
    // =========================================================================

    public function isFree(): bool
    {
        return $this->price === 0;
    }

    public function isPaid(): bool
    {
        return $this->price > 0;
    }

    public function hasUnlimitedMembers(): bool
    {
        return $this->max_members === null;
    }

    /**
     * Get the formatted price in Naira.
     */
    public function formattedPrice(): string
    {
        if ($this->isFree()) {
            return 'Free';
        }

        return '₦'.number_format($this->price, 0);
    }
}
