<?php

use App\Enums\MemberCategory;
use App\Models\Contribution;
use App\Models\Payment;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

describe('Annual Report', function () {
    it('displays annual report page for financial secretary', function () {
        $user = User::factory()->financialSecretary()->create();

        $this->actingAs($user)
            ->get('/reports/annual')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Reports/Annual')
                ->has('year')
            );
    });

    it('displays annual report page for super admin', function () {
        $user = User::factory()->superAdmin()->create();

        $this->actingAs($user)
            ->get('/reports/annual')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Reports/Annual')
            );
    });

    it('includes monthly breakdown for 12 months', function () {
        $admin = User::factory()->superAdmin()->create();
        $member = User::factory()->employed()->create();

        // Create contributions for several months
        $months = [1, 3, 6, 9, 12];
        foreach ($months as $month) {
            $contribution = Contribution::factory()->create([
                'user_id' => $member->id,
                'month' => now()->setYear(now()->year)->setMonth($month)->startOfMonth(),
                'expected_amount' => MemberCategory::Employed->monthlyAmountInKobo(),
            ]);

            Payment::factory()->create([
                'contribution_id' => $contribution->id,
                'amount' => MemberCategory::Employed->monthlyAmountInKobo(),
                'recorded_by' => $admin->id,
            ]);
        }

        $this->actingAs($admin)
            ->get('/reports/annual?year='.now()->year)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Reports/Annual')
                ->has('monthly_breakdown', 12)
            );
    });

    it('includes annual total summary', function () {
        $admin = User::factory()->superAdmin()->create();
        $member = User::factory()->employed()->create();

        // Create contributions for the year
        for ($month = 1; $month <= 3; $month++) {
            $contribution = Contribution::factory()->create([
                'user_id' => $member->id,
                'month' => now()->setYear(now()->year)->setMonth($month)->startOfMonth(),
                'expected_amount' => MemberCategory::Employed->monthlyAmountInKobo(),
            ]);

            Payment::factory()->create([
                'contribution_id' => $contribution->id,
                'amount' => MemberCategory::Employed->monthlyAmountInKobo(),
                'recorded_by' => $admin->id,
            ]);
        }

        $this->actingAs($admin)
            ->get('/reports/annual?year='.now()->year)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Reports/Annual')
                ->has('total', fn (Assert $total) => $total
                    ->has('expected')
                    ->has('collected')
                    ->has('outstanding')
                    ->has('collection_rate')
                    ->etc()
                )
            );
    });

    it('accepts year parameter', function () {
        $admin = User::factory()->superAdmin()->create();

        $this->actingAs($admin)
            ->get('/reports/annual?year=2024')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Reports/Annual')
                ->where('year', 2024)
            );
    });

    it('defaults to current year when no parameter provided', function () {
        $admin = User::factory()->superAdmin()->create();

        $this->actingAs($admin)
            ->get('/reports/annual')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Reports/Annual')
                ->where('year', now()->year)
            );
    });

    it('includes category breakdown for the year', function () {
        $admin = User::factory()->superAdmin()->create();

        // Create members with different categories
        $employed = User::factory()->employed()->create();
        $student = User::factory()->student()->create();

        // Create contributions
        foreach ([$employed, $student] as $member) {
            $contribution = Contribution::factory()->create([
                'user_id' => $member->id,
                'month' => now()->startOfMonth(),
                'expected_amount' => $member->category->monthlyAmountInKobo(),
            ]);

            Payment::factory()->create([
                'contribution_id' => $contribution->id,
                'amount' => $member->category->monthlyAmountInKobo(),
                'recorded_by' => $admin->id,
            ]);
        }

        $this->actingAs($admin)
            ->get('/reports/annual?year='.now()->year)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Reports/Annual')
                ->has('by_category')
            );
    });

    it('shows collection rate trend over months', function () {
        $admin = User::factory()->superAdmin()->create();

        $this->actingAs($admin)
            ->get('/reports/annual?year='.now()->year)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Reports/Annual')
                ->has('monthly_breakdown')
                ->where('monthly_breakdown', fn ($breakdown) => collect($breakdown)->every(fn ($month) => isset($month['collection_rate'])
                ))
            );
    });
});
