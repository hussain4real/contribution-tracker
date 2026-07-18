<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\MemberCategory;
use App\Enums\Role;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $family_id
 * @property int $user_id
 * @property string|null $display_name
 * @property Role $role
 * @property MemberCategory|null $category
 * @property int|null $family_category_id
 * @property Carbon|null $archived_at
 * @property int|null $archived_by
 * @property string|null $archive_reason
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Family $family
 * @property-read User $user
 * @property-read FamilyCategory|null $familyCategory
 * @property-read Collection<int, FamilyMembershipCategoryAssignment> $categoryAssignments
 */
#[Fillable([
    'family_id',
    'user_id',
    'display_name',
    'role',
    'category',
    'family_category_id',
    'archived_at',
    'archived_by',
    'archive_reason',
])]
class FamilyMembership extends Pivot
{
    protected $table = 'family_members';

    public $incrementing = true;

    /**
     * @return BelongsTo<Family, $this>
     */
    public function family(): BelongsTo
    {
        return $this->belongsTo(Family::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<FamilyCategory, $this>
     */
    public function familyCategory(): BelongsTo
    {
        return $this->belongsTo(FamilyCategory::class);
    }

    /** @return HasMany<FamilyMembershipCategoryAssignment, $this> */
    public function categoryAssignments(): HasMany
    {
        return $this->hasMany(FamilyMembershipCategoryAssignment::class, 'family_membership_id', 'id');
    }

    /**
     * @param  Builder<FamilyMembership>  $query
     * @return Builder<FamilyMembership>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull($this->qualifyColumn('archived_at'));
    }

    /**
     * @param  Builder<FamilyMembership>  $query
     * @return Builder<FamilyMembership>
     */
    public function scopeArchived(Builder $query): Builder
    {
        return $query->whereNotNull($this->qualifyColumn('archived_at'));
    }

    public function displayName(): string
    {
        return $this->display_name ?? $this->user->name;
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    public function categoryForPeriod(int $year, int $month): ?FamilyCategory
    {
        $period = Carbon::createFromDate($year, $month, 1)->startOfMonth();
        $assignment = $this->categoryAssignments()
            ->with('category')
            ->effectiveOn($period)
            ->latest('effective_from')
            ->first();

        return $assignment instanceof FamilyMembershipCategoryAssignment
            ? $assignment->category
            : $this->familyCategory;
    }

    public function monthlyAmountForPeriod(int $year, int $month): ?int
    {
        $period = Carbon::createFromDate($year, $month, 1)->startOfMonth();
        $assignment = $this->categoryAssignments()
            ->effectiveOn($period)
            ->latest('effective_from')
            ->first();

        if ($assignment instanceof FamilyMembershipCategoryAssignment) {
            return $assignment->monthly_amount;
        }

        $category = $this->categoryForPeriod($year, $month);

        if ($category instanceof FamilyCategory) {
            return $category->monthly_amount;
        }

        return $this->category?->monthlyAmount();
    }

    /**
     * @return array{family_category_id: int|null, category_name: string|null, category_slug: string|null, category_amount: int|null}
     */
    public function contributionCategorySnapshot(int $year, int $month): array
    {
        $period = Carbon::createFromDate($year, $month, 1)->startOfMonth();
        $assignment = $this->categoryAssignments()
            ->effectiveOn($period)
            ->latest('effective_from')
            ->first();
        $category = $assignment instanceof FamilyMembershipCategoryAssignment
            ? $assignment->category
            : $this->categoryForPeriod($year, $month);

        if ($assignment instanceof FamilyMembershipCategoryAssignment) {
            return [
                'family_category_id' => $assignment->family_category_id,
                'category_name' => $assignment->category_name,
                'category_slug' => $assignment->category_slug,
                'category_amount' => $assignment->monthly_amount,
            ];
        }

        if ($category instanceof FamilyCategory) {
            return [
                'family_category_id' => $category->id,
                'category_name' => $category->name,
                'category_slug' => $category->slug,
                'category_amount' => $category->monthly_amount,
            ];
        }

        return [
            'family_category_id' => null,
            'category_name' => $this->category?->label(),
            'category_slug' => $this->category?->value,
            'category_amount' => $this->category?->monthlyAmount(),
        ];
    }

    public function monthlyAmount(): ?int
    {
        if ($this->familyCategory instanceof FamilyCategory) {
            return $this->familyCategory->monthly_amount;
        }

        return $this->category?->monthlyAmount();
    }

    public function categoryLabel(): ?string
    {
        if ($this->familyCategory instanceof FamilyCategory) {
            return $this->familyCategory->name;
        }

        return $this->category?->label();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => Role::class,
            'category' => MemberCategory::class,
            'archived_at' => 'datetime',
        ];
    }
}
