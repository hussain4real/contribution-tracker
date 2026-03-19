<?php

namespace Database\Seeders;

use App\Enums\MemberCategory;
use App\Enums\Role;
use App\Mail\TwoFactorRecoveryCodesMail;
use App\Models\Contribution;
use App\Models\Family;
use App\Models\FamilyCategory;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

class FamilyMemberSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Create a family
        $family = Family::create([
            'name' => 'Demo Family',
            'slug' => 'demo-family',
            'currency' => '₦',
            'due_day' => 28,
        ]);

        // Create family categories
        $employedCategory = FamilyCategory::create([
            'family_id' => $family->id,
            'name' => 'Employed',
            'slug' => 'employed',
            'monthly_amount' => 4000,
            'sort_order' => 0,
        ]);

        $unemployedCategory = FamilyCategory::create([
            'family_id' => $family->id,
            'name' => 'Unemployed',
            'slug' => 'unemployed',
            'monthly_amount' => 2000,
            'sort_order' => 1,
        ]);

        $studentCategory = FamilyCategory::create([
            'family_id' => $family->id,
            'name' => 'Student',
            'slug' => 'student',
            'monthly_amount' => 1000,
            'sort_order' => 2,
        ]);

        // Create Admin
        $admin = User::factory()->create([
            'name' => 'Admin',
            'email' => 'admin@family.test',
            'password' => Hash::make('password'),
            'role' => Role::Admin,
            'category' => null,
            'family_id' => $family->id,
            'family_category_id' => null,
        ]);

        // Set family owner
        $family->update(['created_by' => $admin->id]);

        Mail::to($admin)->send(new TwoFactorRecoveryCodesMail($admin, $admin->recoveryCodes()));

        // Create Financial Secretary
        $financialSecretary = User::factory()->create([
            'name' => 'Financial Secretary',
            'email' => 'finance@family.test',
            'password' => Hash::make('password'),
            'role' => Role::FinancialSecretary,
            'category' => MemberCategory::Employed,
            'family_id' => $family->id,
            'family_category_id' => $employedCategory->id,
        ]);

        // Create regular members with different categories
        $employedMember = User::factory()->create([
            'name' => 'Employed Member',
            'email' => 'employed@family.test',
            'password' => Hash::make('password'),
            'role' => Role::Member,
            'category' => MemberCategory::Employed,
            'family_id' => $family->id,
            'family_category_id' => $employedCategory->id,
        ]);

        $unemployedMember = User::factory()->create([
            'name' => 'Unemployed Member',
            'email' => 'unemployed@family.test',
            'password' => Hash::make('password'),
            'role' => Role::Member,
            'category' => MemberCategory::Unemployed,
            'family_id' => $family->id,
            'family_category_id' => $unemployedCategory->id,
        ]);

        $studentMember = User::factory()->create([
            'name' => 'Student Member',
            'email' => 'student@family.test',
            'password' => Hash::make('password'),
            'role' => Role::Member,
            'category' => MemberCategory::Student,
            'family_id' => $family->id,
            'family_category_id' => $studentCategory->id,
        ]);

        // Create contributions for current and previous month
        $currentYear = now()->year;
        $currentMonth = now()->month;
        $previousMonth = now()->subMonth();

        $payingMembers = [$financialSecretary, $employedMember, $unemployedMember, $studentMember];

        foreach ($payingMembers as $member) {
            // Previous month contribution (fully paid)
            $previousContribution = Contribution::create([
                'family_id' => $family->id,
                'user_id' => $member->id,
                'year' => $previousMonth->year,
                'month' => $previousMonth->month,
                'expected_amount' => $member->category->monthlyAmount(),
                'due_date' => $previousMonth->copy()->setDay($family->due_day),
            ]);

            Payment::create([
                'contribution_id' => $previousContribution->id,
                'amount' => $member->category->monthlyAmount(),
                'paid_at' => $previousMonth->setDay(15),
                'recorded_by' => $financialSecretary->id,
                'notes' => 'Seeded payment',
            ]);

            // Current month contribution
            $currentContribution = Contribution::create([
                'family_id' => $family->id,
                'user_id' => $member->id,
                'year' => $currentYear,
                'month' => $currentMonth,
                'expected_amount' => $member->category->monthlyAmount(),
                'due_date' => now()->setDay($family->due_day),
            ]);

            // Only financial secretary has paid current month (partial for demo)
            if ($member->id === $financialSecretary->id) {
                Payment::create([
                    'contribution_id' => $currentContribution->id,
                    'amount' => $member->category->monthlyAmount(),
                    'paid_at' => now()->setDay(10),
                    'recorded_by' => $financialSecretary->id,
                    'notes' => 'Seeded payment',
                ]);
            } elseif ($member->id === $employedMember->id) {
                // Employed member has partial payment
                Payment::create([
                    'contribution_id' => $currentContribution->id,
                    'amount' => 2000, // ₦2,000 partial of ₦4,000
                    'paid_at' => now()->setDay(5),
                    'recorded_by' => $financialSecretary->id,
                    'notes' => 'Partial payment - seeded',
                ]);
            }
            // Unemployed and student have no payments for current month (unpaid)
        }

        // Create one archived member
        User::factory()->archived()->create([
            'name' => 'Archived Member',
            'email' => 'archived@family.test',
            'password' => Hash::make('password'),
            'role' => Role::Member,
            'category' => MemberCategory::Employed,
            'family_id' => $family->id,
            'family_category_id' => $employedCategory->id,
        ]);
    }
}
