<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Role;
use App\Models\Family;
use App\Models\FamilyCategory;
use App\Models\FamilyMembership;
use App\Models\FamilyMembershipCategoryAssignment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FamilyMembershipCategoryAssignment>
 */
class FamilyMembershipCategoryAssignmentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<model-property<FamilyMembershipCategoryAssignment>, mixed>
     */
    public function definition(): array
    {
        return [
            'family_membership_id' => function (): int {
                $family = Family::factory()->create();
                $user = User::factory()->for($family)->create();

                return FamilyMembership::query()->firstOrCreate([
                    'family_id' => $family->id,
                    'user_id' => $user->id,
                ], [
                    'display_name' => $user->name,
                    'role' => Role::Member,
                ])->id;
            },
            'family_category_id' => function (array $attributes): int {
                $membershipId = $attributes['family_membership_id'] ?? null;

                if (! is_int($membershipId)) {
                    return FamilyCategory::factory()->create()->id;
                }

                $membership = FamilyMembership::query()->whereKey($membershipId)->firstOrFail();

                return FamilyCategory::factory()->create(['family_id' => $membership->family_id])->id;
            },
            'assigned_by' => null,
            'category_name' => 'Member',
            'category_slug' => 'member',
            'monthly_amount' => 1000,
            'effective_from' => now()->startOfMonth(),
            'effective_until' => null,
        ];
    }
}
