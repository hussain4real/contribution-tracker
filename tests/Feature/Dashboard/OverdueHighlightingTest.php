<?php

use App\Models\Contribution;
use App\Models\User;
use Carbon\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * T044 [US2] Feature test for overdue highlighting
 *
 * FR-006: System MUST mark contributions as overdue when unpaid after the 28th
 */
describe('Overdue Highlighting', function () {
    beforeEach(function () {
        $this->superAdmin = User::factory()->superAdmin()->create();
        $this->member = User::factory()->member()->employed()->create();
    });

    it('shows overdue count when contributions are past due', function () {
        // Travel to a date past the due date (e.g., 29th of last month)
        Carbon::setTestNow(Carbon::now()->startOfMonth()->addDays(28));

        // Create contribution for last month (which is now overdue)
        $lastMonth = now()->subMonth();
        Contribution::factory()
            ->forUser($this->member)
            ->forMonth($lastMonth->year, $lastMonth->month)
            ->employed()
            ->create();

        $this->actingAs($this->superAdmin)
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('summary.overdue_count', fn ($count) => $count > 0)
            );

        Carbon::setTestNow(); // Reset time
    });

    it('shows zero overdue when all contributions are paid', function () {
        // Create contribution for current month
        $contribution = Contribution::factory()
            ->forUser($this->member)
            ->currentMonth()
            ->employed()
            ->create();

        // Fully pay it
        $contribution->payments()->create([
            'amount' => 400000,
            'paid_at' => now(),
            'recorded_by' => $this->superAdmin->id,
        ]);

        $this->actingAs($this->superAdmin)
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('summary.overdue_count', 0)
            );
    });

    it('member statuses show overdue flag correctly', function () {
        // Travel to a date past the due date
        Carbon::setTestNow(Carbon::now()->startOfMonth()->addDays(28));

        // Create overdue contribution for last month
        $lastMonth = now()->subMonth();
        Contribution::factory()
            ->forUser($this->member)
            ->forMonth($lastMonth->year, $lastMonth->month)
            ->employed()
            ->create();

        // Also create current month contribution (so member appears in member_statuses)
        Contribution::factory()
            ->forUser($this->member)
            ->currentMonth()
            ->employed()
            ->create();

        $this->actingAs($this->superAdmin)
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('member_statuses.0', fn (Assert $status) => $status
                    ->has('has_overdue')
                    ->where('has_overdue', true) // Should be true since they have overdue from last month
                    ->etc()
                )
            );

        Carbon::setTestNow(); // Reset time
    });

    it('counts only incomplete contributions as overdue', function () {
        Carbon::setTestNow(Carbon::now()->startOfMonth()->addDays(28));

        $lastMonth = now()->subMonth();

        // Create two overdue contributions
        $member2 = User::factory()->member()->student()->create();

        // First member - unpaid (overdue)
        Contribution::factory()
            ->forUser($this->member)
            ->forMonth($lastMonth->year, $lastMonth->month)
            ->employed()
            ->create();

        // Second member - fully paid (not overdue)
        $paidContribution = Contribution::factory()
            ->forUser($member2)
            ->forMonth($lastMonth->year, $lastMonth->month)
            ->student()
            ->create();

        $paidContribution->payments()->create([
            'amount' => 100000,
            'paid_at' => now(),
            'recorded_by' => $this->superAdmin->id,
        ]);

        $this->actingAs($this->superAdmin)
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('summary.overdue_count', 1) // Only one overdue
            );

        Carbon::setTestNow();
    });
});
