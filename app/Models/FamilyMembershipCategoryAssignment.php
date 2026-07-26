<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\FamilyMembershipCategoryAssignmentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $family_membership_id
 * @property int $family_category_id
 * @property int|null $assigned_by
 * @property string $category_name
 * @property string $category_slug
 * @property int $monthly_amount
 * @property Carbon $effective_from
 * @property Carbon|null $effective_until
 * @property-read FamilyMembership $membership
 * @property-read FamilyCategory $category
 * @property-read User|null $assigner
 */
class FamilyMembershipCategoryAssignment extends Model
{
    /** @use HasFactory<FamilyMembershipCategoryAssignmentFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'family_membership_id',
        'family_category_id',
        'assigned_by',
        'category_name',
        'category_slug',
        'monthly_amount',
        'effective_from',
        'effective_until',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'effective_from' => 'date',
            'effective_until' => 'date',
            'monthly_amount' => 'integer',
        ];
    }

    /** @return BelongsTo<FamilyMembership, $this> */
    public function membership(): BelongsTo
    {
        return $this->belongsTo(FamilyMembership::class, 'family_membership_id');
    }

    /** @return BelongsTo<FamilyCategory, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(FamilyCategory::class, 'family_category_id');
    }

    /** @return BelongsTo<User, $this> */
    public function assigner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    /**
     * @param  Builder<FamilyMembershipCategoryAssignment>  $query
     * @return Builder<FamilyMembershipCategoryAssignment>
     */
    public function scopeEffectiveOn(Builder $query, Carbon $date): Builder
    {
        return $query
            ->whereDate('effective_from', '<=', $date)
            ->where(function (Builder $query) use ($date): void {
                $query->whereNull('effective_until')
                    ->orWhereDate('effective_until', '>=', $date);
            });
    }
}
