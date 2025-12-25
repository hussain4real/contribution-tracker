<?php

namespace Database\Seeders;

use App\Enums\MemberCategory;
use App\Enums\Role;
use App\Models\Contribution;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class FamilyMemberSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Create Super Admin
        $admin = User::factory()->create([
            'name' => 'Super Admin',
            'email' => 'admin@family.test',
            'password' => Hash::make('password'),
            'role' => Role::SuperAdmin,
            'category' => null, // Super Admin doesn't pay contributions
        ]);

        // Create Financial Secretary
        $financialSecretary = User::factory()->create([
            'name' => 'Financial Secretary',
            'email' => 'finance@family.test',
            'password' => Hash::make('password'),
            'role' => Role::FinancialSecretary,
            'category' => MemberCategory::Employed,
        ]);

        // Create regular members with different categories
        $employedMember = User::factory()->create([
            'name' => 'Employed Member',
            'email' => 'employed@family.test',
            'password' => Hash::make('password'),
            'role' => Role::Member,
            'category' => MemberCategory::Employed,
        ]);

        $unemployedMember = User::factory()->create([
            'name' => 'Unemployed Member',
            'email' => 'unemployed@family.test',
            'password' => Hash::make('password'),
            'role' => Role::Member,
            'category' => MemberCategory::Unemployed,
        ]);

        $studentMember = User::factory()->create([
            'name' => 'Student Member',
            'email' => 'student@family.test',
            'password' => Hash::make('password'),
            'role' => Role::Member,
            'category' => MemberCategory::Student,
        ]);

        // Create contributions for current and previous month
        $currentYear = now()->year;
        $currentMonth = now()->month;
        $previousMonth = now()->subMonth();

        $payingMembers = [$financialSecretary, $employedMember, $unemployedMember, $studentMember];

        foreach ($payingMembers as $member) {
            // Previous month contribution (fully paid)
            $previousContribution = Contribution::create([
                'user_id' => $member->id,
                'year' => $previousMonth->year,
                'month' => $previousMonth->month,
                'expected_amount' => $member->category->monthlyAmountInKobo(),
            ]);

            Payment::create([
                'contribution_id' => $previousContribution->id,
                'amount' => $member->category->monthlyAmountInKobo(),
                'paid_at' => $previousMonth->setDay(15),
                'recorded_by' => $financialSecretary->id,
                'notes' => 'Seeded payment',
            ]);

            // Current month contribution
            $currentContribution = Contribution::create([
                'user_id' => $member->id,
                'year' => $currentYear,
                'month' => $currentMonth,
                'expected_amount' => $member->category->monthlyAmountInKobo(),
            ]);

            // Only financial secretary has paid current month (partial for demo)
            if ($member->id === $financialSecretary->id) {
                Payment::create([
                    'contribution_id' => $currentContribution->id,
                    'amount' => $member->category->monthlyAmountInKobo(),
                    'paid_at' => now()->setDay(10),
                    'recorded_by' => $financialSecretary->id,
                    'notes' => 'Seeded payment',
                ]);
            } elseif ($member->id === $employedMember->id) {
                // Employed member has partial payment
                Payment::create([
                    'contribution_id' => $currentContribution->id,
                    'amount' => 200000, // ₦2,000 partial of ₦4,000
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
        ]);
    }
}
