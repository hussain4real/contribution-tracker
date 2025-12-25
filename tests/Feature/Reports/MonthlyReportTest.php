<?php

use App\Enums\MemberCategory;
use App\Enums\PaymentStatus;
use App\Models\Contribution;
use App\Models\Payment;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

describe('Monthly Report', function () {
    it('displays monthly report page for financial secretary', function () {
        $user = User::factory()->financialSecretary()->create();

        $this->actingAs($user)
            ->get('/reports/monthly')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Reports/Monthly')
                ->has('year')
                ->has('month')
            );
    });

    it('displays monthly report page for super admin', function () {
        $user = User::factory()->superAdmin()->create();

        $this->actingAs($user)
            ->get('/reports/monthly')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Reports/Monthly')
            );
    });

    it('includes summary with total expected and collected', function () {
        $admin = User::factory()->superAdmin()->create();

        // Create members with different categories
        $employed = User::factory()->employed()->create();
        $student = User::factory()->student()->create();

        // Create contributions for current month
        $contribution1 = Contribution::factory()->create([
            'user_id' => $employed->id,
            'month' => now()->startOfMonth(),
            'expected_amount' => MemberCategory::Employed->monthlyAmountInKobo(),
        ]);

        $contribution2 = Contribution::factory()->create([
            'user_id' => $student->id,
            'month' => now()->startOfMonth(),
            'expected_amount' => MemberCategory::Student->monthlyAmountInKobo(),
        ]);

        // Add payments
        Payment::factory()->create([
            'contribution_id' => $contribution1->id,
            'amount' => MemberCategory::Employed->monthlyAmountInKobo(),
            'recorded_by' => $admin->id,
        ]);

        Payment::factory()->create([
            'contribution_id' => $contribution2->id,
            'amount' => 50000, // Partial payment
            'recorded_by' => $admin->id,
        ]);

        $this->actingAs($admin)
            ->get('/reports/monthly?year='.now()->year.'&month='.now()->month)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Reports/Monthly')
                ->has('summary', fn (Assert $summary) => $summary
                    ->has('total_expected')
                    ->has('total_collected')
                    ->has('total_outstanding')
                    ->has('collection_rate')
                    ->has('member_count')
                    ->etc()
                )
            );
    });

    it('includes breakdown by category', function () {
        $admin = User::factory()->superAdmin()->create();

        // Create members with different categories
        User::factory()->employed()->count(3)->create();
        User::factory()->student()->count(2)->create();

        // Create contributions for current month
        User::query()
            ->whereNot('id', $admin->id)
            ->get()
            ->each(function ($user) {
                Contribution::factory()->create([
                    'user_id' => $user->id,
                    'month' => now()->startOfMonth(),
                    'expected_amount' => $user->category->monthlyAmountInKobo(),
                ]);
            });

        $this->actingAs($admin)
            ->get('/reports/monthly?year='.now()->year.'&month='.now()->month)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Reports/Monthly')
                ->has('by_category')
                ->where('by_category', fn ($categories) => isset($categories['employed']) && isset($categories['student']))
            );
    });

    it('includes member statuses for the month', function () {
        $admin = User::factory()->superAdmin()->create();
        $member = User::factory()->employed()->create();

        $contribution = Contribution::factory()->create([
            'user_id' => $member->id,
            'month' => now()->startOfMonth(),
            'expected_amount' => MemberCategory::Employed->monthlyAmountInKobo(),
        ]);

        Payment::factory()->create([
            'contribution_id' => $contribution->id,
            'amount' => MemberCategory::Employed->monthlyAmountInKobo(),
            'recorded_by' => $admin->id,
        ]);

        $this->actingAs($admin)
            ->get('/reports/monthly?year='.now()->year.'&month='.now()->month)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Reports/Monthly')
                ->has('members', fn (Assert $members) => $members
                    ->has('data')
                    ->etc()
                )
            );
    });

    it('accepts year and month parameters', function () {
        $admin = User::factory()->superAdmin()->create();

        $this->actingAs($admin)
            ->get('/reports/monthly?year=2024&month=6')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Reports/Monthly')
                ->where('year', 2024)
                ->where('month', 6)
            );
    });

    it('defaults to current month when no parameters provided', function () {
        $admin = User::factory()->superAdmin()->create();

        $this->actingAs($admin)
            ->get('/reports/monthly')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Reports/Monthly')
                ->where('year', now()->year)
                ->where('month', now()->month)
            );
    });

    it('shows status counts (paid, partial, unpaid, overdue)', function () {
        $admin = User::factory()->superAdmin()->create();

        // Create members
        $members = User::factory()->employed()->count(4)->create();

        // Create contributions with different statuses
        foreach ($members as $index => $member) {
            $contribution = Contribution::factory()->create([
                'user_id' => $member->id,
                'month' => now()->startOfMonth(),
                'expected_amount' => MemberCategory::Employed->monthlyAmountInKobo(),
            ]);

            // First member: fully paid
            if ($index === 0) {
                Payment::factory()->create([
                    'contribution_id' => $contribution->id,
                    'amount' => MemberCategory::Employed->monthlyAmountInKobo(),
                    'recorded_by' => $admin->id,
                ]);
            }
            // Second member: partially paid
            elseif ($index === 1) {
                Payment::factory()->create([
                    'contribution_id' => $contribution->id,
                    'amount' => 200000,
                    'recorded_by' => $admin->id,
                ]);
            }
            // Third and fourth: unpaid
        }

        $this->actingAs($admin)
            ->get('/reports/monthly?year='.now()->year.'&month='.now()->month)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Reports/Monthly')
                ->has('summary.status_counts', fn (Assert $counts) => $counts
                    ->has(PaymentStatus::Paid->value)
                    ->has(PaymentStatus::Partial->value)
                    ->has(PaymentStatus::Unpaid->value)
                    ->etc()
                )
            );
    });
});
