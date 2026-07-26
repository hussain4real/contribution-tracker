<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\MemberCategory;
use App\Enums\Role;
use App\Models\Family;
use App\Models\FamilyCategory;
use App\Models\FamilyMembership;
use App\Models\FamilyMembershipCategoryAssignment;
use App\Models\PlatformPlan;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;

class DemoIdentitySeeder extends Seeder
{
    public function run(): void
    {
        $now = CarbonImmutable::today();
        $plans = PlatformPlan::query()->get()->keyBy('slug');

        $platformAdmin = $this->user(
            email: 'platform@family.test',
            name: 'Platform Administrator',
            role: Role::Admin,
            isSuperAdmin: true,
        );

        $primaryFamily = $this->family('demo-family', [
            'name' => 'FamilyFund Demo Family',
            'currency' => '₦',
            'due_day' => 28,
            'bank_name' => 'Demo Community Bank',
            'bank_code' => '999',
            'account_name' => 'FamilyFund Demo Family',
            'account_number' => '0123456789',
            'paystack_subaccount_code' => 'ACCT_demo_family',
            'paystack_customer_code' => 'CUS_demo_family',
            'paystack_subscription_code' => 'SUB_demo_family',
            'subscription_status' => 'active',
            'current_period_end' => $now->addMonth(),
            'platform_plan_id' => $plans->get('growth')?->id,
            'max_members' => 75,
        ]);
        $freeFamily = $this->family('free-family', [
            'name' => 'Free Plan Family',
            'platform_plan_id' => $plans->get('free')?->id,
            'subscription_status' => 'free',
            'trial_ends_at' => $now->addDays(7),
            'max_members' => 5,
        ]);
        $organizationFamily = $this->family('organization-family', [
            'name' => 'Organization Plan Family',
            'currency' => '$',
            'due_day' => 15,
            'platform_plan_id' => $plans->get('organization')?->id,
            'subscription_status' => 'active',
            'current_period_end' => $now->addMonth(),
            'max_members' => 250,
        ]);
        $suspendedFamily = $this->family('suspended-family', [
            'name' => 'Suspended Family',
            'platform_plan_id' => $plans->get('family')?->id,
            'subscription_status' => 'past_due',
            'suspended_at' => $now->subDays(3),
            'max_members' => 25,
        ]);
        $archivedFamily = $this->family('archived-family', [
            'name' => 'Archived Family',
            'platform_plan_id' => $plans->get('family')?->id,
            'subscription_status' => 'cancelled',
            'archived_at' => $now->subDays(10),
            'archive_reason' => 'Demo tenant retained for restore and export testing.',
            'purge_after' => $now->addDays(20),
        ]);
        $legalHoldFamily = $this->family('legal-hold-family', [
            'name' => 'Legal Hold Family',
            'platform_plan_id' => $plans->get('organization')?->id,
            'subscription_status' => 'cancelled',
            'archived_at' => $now->subDays(40),
            'archive_reason' => 'Demo tenant past its normal retention period.',
            'purge_after' => $now->subDays(10),
            'legal_hold_at' => $now->subDays(15),
            'legal_hold_by' => $platformAdmin->id,
            'legal_hold_reason' => 'Demo legal hold for platform administration testing.',
        ]);

        $primaryCategories = $this->categories($primaryFamily);
        $freeCategories = $this->categories($freeFamily);
        $organizationCategories = $this->categories($organizationFamily);
        $suspendedCategories = $this->categories($suspendedFamily);
        $archivedCategories = $this->categories($archivedFamily);
        $legalHoldCategories = $this->categories($legalHoldFamily);

        $admin = $this->familyUser($primaryFamily, $primaryCategories, 'admin@family.test', 'Demo Family Admin', Role::Admin);
        $finance = $this->familyUser($primaryFamily, $primaryCategories, 'finance@family.test', 'Demo Financial Secretary', Role::FinancialSecretary, MemberCategory::Employed, '+97455000001');
        $member = $this->familyUser($primaryFamily, $primaryCategories, 'member@family.test', 'Amina Fully Paid', Role::Member, MemberCategory::Employed, '+97455000002');
        $partial = $this->familyUser($primaryFamily, $primaryCategories, 'partial@family.test', 'Bilal Partial Payment', Role::Member, MemberCategory::Employed, '+97455000003');
        $overdue = $this->familyUser($primaryFamily, $primaryCategories, 'overdue@family.test', 'Chidi Overdue Member', Role::Member, MemberCategory::Unemployed, '+97455000004');
        $student = $this->familyUser($primaryFamily, $primaryCategories, 'student@family.test', 'Dalia Student Member', Role::Member, MemberCategory::Student);
        $multiFamilyMember = $this->familyUser($primaryFamily, $primaryCategories, 'multi@family.test', 'Ehsan Multi Family', Role::Member, MemberCategory::Employed);
        $this->familyUser($primaryFamily, $primaryCategories, 'managed@family.test', 'Fatima Managed Account', Role::Member, MemberCategory::Student, mustChangePassword: true);
        $archivedMember = $this->familyUser($primaryFamily, $primaryCategories, 'archived.member@family.test', 'Grace Archived Membership', Role::Member, MemberCategory::Employed);

        $archivedMembership = $this->membership($primaryFamily, $archivedMember, Role::Member, $primaryCategories['employed']);
        $archivedMembership->forceFill([
            'archived_at' => $now->subDays(14),
            'archived_by' => $admin->id,
            'archive_reason' => 'Demo family-scoped member archive.',
        ])->save();

        $freeAdmin = $this->familyUser($freeFamily, $freeCategories, 'free.admin@family.test', 'Free Plan Admin', Role::Admin);
        foreach (range(1, 4) as $index) {
            $this->familyUser(
                $freeFamily,
                $freeCategories,
                "free.member{$index}@family.test",
                "Free Family Member {$index}",
                Role::Member,
                MemberCategory::Employed,
            );
        }

        $organizationAdmin = $this->familyUser($organizationFamily, $organizationCategories, 'organization.admin@family.test', 'Organization Admin', Role::Admin);
        $this->membership($organizationFamily, $multiFamilyMember, Role::FinancialSecretary, $organizationCategories['unemployed'], 'Ehsan Organization Treasurer');

        $suspendedAdmin = $this->familyUser($suspendedFamily, $suspendedCategories, 'suspended.admin@family.test', 'Suspended Family Admin', Role::Admin);
        $archivedAdmin = $this->familyUser($archivedFamily, $archivedCategories, 'archived.admin@family.test', 'Archived Family Admin', Role::Admin);
        $legalHoldAdmin = $this->familyUser($legalHoldFamily, $legalHoldCategories, 'legalhold.admin@family.test', 'Legal Hold Family Admin', Role::Admin);

        $this->setOwner($primaryFamily, $admin);
        $this->setOwner($freeFamily, $freeAdmin);
        $this->setOwner($organizationFamily, $organizationAdmin);
        $this->setOwner($suspendedFamily, $suspendedAdmin);
        $this->setOwner($archivedFamily, $archivedAdmin);
        $this->setOwner($legalHoldFamily, $legalHoldAdmin);
        $archivedFamily->forceFill(['archived_by' => $archivedAdmin->id])->save();
        $legalHoldFamily->forceFill(['archived_by' => $legalHoldAdmin->id])->save();

        $this->seedCategoryHistory($primaryFamily, $admin, $primaryCategories, [
            $finance,
            $member,
            $overdue,
            $student,
            $multiFamilyMember,
        ]);
        $this->seedChangedCategoryHistory($primaryFamily, $partial, $admin, $primaryCategories);
    }

    /** @param array<string, mixed> $attributes */
    private function family(string $slug, array $attributes): Family
    {
        return Family::query()->updateOrCreate(['slug' => $slug], $attributes);
    }

    /** @return array<string, FamilyCategory> */
    private function categories(Family $family): array
    {
        $definitions = [
            'employed' => ['name' => 'Employed', 'monthly_amount' => 4000, 'sort_order' => 0],
            'unemployed' => ['name' => 'Unemployed', 'monthly_amount' => 2000, 'sort_order' => 1],
            'student' => ['name' => 'Student', 'monthly_amount' => 1000, 'sort_order' => 2],
            'senior' => ['name' => 'Senior', 'monthly_amount' => 1500, 'sort_order' => 3],
        ];

        $categories = [];

        foreach ($definitions as $slug => $definition) {
            $categories[$slug] = FamilyCategory::query()->updateOrCreate(
                ['family_id' => $family->id, 'slug' => $slug],
                $definition,
            );
        }

        return $categories;
    }

    private function user(
        string $email,
        string $name,
        Role $role,
        ?Family $family = null,
        ?FamilyCategory $category = null,
        bool $isSuperAdmin = false,
        ?string $whatsAppPhone = null,
        bool $mustChangePassword = false,
    ): User {
        $user = User::query()->firstOrNew(['email' => $email]);
        $user->forceFill([
            'name' => $name,
            'email_verified_at' => now(),
            'password' => 'password',
            'role' => $role,
            'category' => $category === null ? null : MemberCategory::tryFrom($category->slug),
            'family_id' => $family?->id,
            'current_family_id' => $family?->id,
            'family_category_id' => $category?->id,
            'is_super_admin' => $isSuperAdmin,
            'must_change_password_at' => $mustChangePassword ? now() : null,
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
            'whatsapp_phone' => $whatsAppPhone,
            'whatsapp_verified_at' => $whatsAppPhone === null ? null : now(),
        ])->save();

        return $user;
    }

    /** @param array<string, FamilyCategory> $categories */
    private function familyUser(
        Family $family,
        array $categories,
        string $email,
        string $name,
        Role $role,
        ?MemberCategory $memberCategory = null,
        ?string $whatsAppPhone = null,
        bool $mustChangePassword = false,
    ): User {
        $category = $memberCategory === null ? null : $categories[$memberCategory->value];
        $user = $this->user($email, $name, $role, $family, $category, whatsAppPhone: $whatsAppPhone, mustChangePassword: $mustChangePassword);
        $this->membership($family, $user, $role, $category);

        return $user;
    }

    private function membership(
        Family $family,
        User $user,
        Role $role,
        ?FamilyCategory $category,
        ?string $displayName = null,
    ): FamilyMembership {
        return FamilyMembership::query()->updateOrCreate(
            ['family_id' => $family->id, 'user_id' => $user->id],
            [
                'display_name' => $displayName ?? $user->name,
                'role' => $role,
                'category' => $category === null ? null : MemberCategory::tryFrom($category->slug),
                'family_category_id' => $category?->id,
            ],
        );
    }

    private function setOwner(Family $family, User $owner): void
    {
        $family->forceFill(['created_by' => $owner->id])->save();
    }

    /**
     * @param  array<string, FamilyCategory>  $categories
     * @param  list<User>  $users
     */
    private function seedCategoryHistory(Family $family, User $actor, array $categories, array $users): void
    {
        foreach ($users as $user) {
            $currentMembership = $user->membershipForFamilyId($family->id);
            $currentCategory = $user->familyCategory;
            $membership = $this->membership(
                $family,
                $user,
                $currentMembership instanceof FamilyMembership ? $currentMembership->role : Role::Member,
                $currentCategory instanceof FamilyCategory ? $categories[$currentCategory->slug] : $categories['employed'],
            );
            $category = $membership->familyCategory;

            if ($category instanceof FamilyCategory) {
                $this->assignment($membership, $category, $actor, CarbonImmutable::now()->subMonths(6)->startOfMonth());
            }
        }
    }

    /** @param array<string, FamilyCategory> $categories */
    private function seedChangedCategoryHistory(Family $family, User $user, User $actor, array $categories): void
    {
        $membership = $this->membership($family, $user, Role::Member, $categories['employed']);
        $firstPeriod = CarbonImmutable::now()->subMonths(6)->startOfMonth();
        $changePeriod = CarbonImmutable::now()->subMonths(3)->startOfMonth();

        $this->assignment($membership, $categories['student'], $actor, $firstPeriod, $changePeriod->subDay());
        $this->assignment($membership, $categories['employed'], $actor, $changePeriod);
    }

    private function assignment(
        FamilyMembership $membership,
        FamilyCategory $category,
        User $actor,
        CarbonImmutable $effectiveFrom,
        ?CarbonImmutable $effectiveUntil = null,
    ): void {
        $assignment = FamilyMembershipCategoryAssignment::query()
            ->where('family_membership_id', $membership->id)
            ->whereDate('effective_from', $effectiveFrom)
            ->first() ?? new FamilyMembershipCategoryAssignment;

        $assignment->forceFill([
            'family_membership_id' => $membership->id,
            'family_category_id' => $category->id,
            'assigned_by' => $actor->id,
            'category_name' => $category->name,
            'category_slug' => $category->slug,
            'monthly_amount' => $category->monthly_amount,
            'effective_from' => $effectiveFrom,
            'effective_until' => $effectiveUntil,
        ])->save();
    }
}
