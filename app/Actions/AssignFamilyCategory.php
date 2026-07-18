<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\FamilyCategory;
use App\Models\FamilyMembership;
use App\Models\FamilyMembershipCategoryAssignment;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class AssignFamilyCategory
{
    public function handle(
        FamilyMembership $membership,
        ?FamilyCategory $category,
        ?User $actor,
        bool $effectiveImmediately = false,
    ): FamilyMembership {
        if ($category instanceof FamilyCategory && $category->family_id !== $membership->family_id) {
            throw new InvalidArgumentException('The selected category does not belong to this family.');
        }

        return DB::transaction(function () use ($membership, $category, $actor, $effectiveImmediately): FamilyMembership {
            $lockedMembership = FamilyMembership::query()
                ->with('familyCategory')
                ->lockForUpdate()
                ->findOrFail($membership->id);

            $effectiveFrom = $effectiveImmediately
                ? now()->startOfMonth()
                : now()->addMonthNoOverflow()->startOfMonth();

            $this->ensureHistoricalAssignment($lockedMembership, $effectiveFrom, $actor);

            FamilyMembershipCategoryAssignment::query()
                ->where('family_membership_id', $lockedMembership->id)
                ->whereDate('effective_from', '>=', $effectiveFrom)
                ->delete();

            FamilyMembershipCategoryAssignment::query()
                ->where('family_membership_id', $lockedMembership->id)
                ->whereDate('effective_from', '<', $effectiveFrom)
                ->where(function ($query) use ($effectiveFrom): void {
                    $query->whereNull('effective_until')
                        ->orWhereDate('effective_until', '>=', $effectiveFrom);
                })
                ->update(['effective_until' => $effectiveFrom->copy()->subDay()->toDateString()]);

            if ($category instanceof FamilyCategory) {
                FamilyMembershipCategoryAssignment::query()->create([
                    'family_membership_id' => $lockedMembership->id,
                    'family_category_id' => $category->id,
                    'assigned_by' => $actor?->id,
                    'category_name' => $category->name,
                    'category_slug' => $category->slug,
                    'monthly_amount' => $category->monthly_amount,
                    'effective_from' => $effectiveFrom->toDateString(),
                ]);
            }

            $lockedMembership->forceFill([
                'family_category_id' => $category?->id,
                'category' => null,
            ])->save();

            return $lockedMembership->fresh(['familyCategory', 'categoryAssignments.category']) ?? $lockedMembership;
        }, 5);
    }

    private function ensureHistoricalAssignment(
        FamilyMembership $membership,
        Carbon $effectiveFrom,
        ?User $actor,
    ): void {
        if (! $membership->familyCategory instanceof FamilyCategory) {
            return;
        }

        $hasAssignments = FamilyMembershipCategoryAssignment::query()
            ->where('family_membership_id', $membership->id)
            ->exists();

        if ($hasAssignments) {
            return;
        }

        $startsAt = $membership->created_at?->copy()->startOfMonth() ?? now()->startOfMonth();

        FamilyMembershipCategoryAssignment::query()->create([
            'family_membership_id' => $membership->id,
            'family_category_id' => $membership->familyCategory->id,
            'assigned_by' => $actor?->id,
            'category_name' => $membership->familyCategory->name,
            'category_slug' => $membership->familyCategory->slug,
            'monthly_amount' => $membership->familyCategory->monthly_amount,
            'effective_from' => $startsAt->toDateString(),
            'effective_until' => $startsAt->lt($effectiveFrom)
                ? $effectiveFrom->copy()->subDay()->toDateString()
                : null,
        ]);
    }
}
