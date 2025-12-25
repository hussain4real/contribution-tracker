<?php

use App\Models\Contribution;
use App\Models\User;
use Carbon\Carbon;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

describe('Contribution Due Date', function () {
    it('due_date accessor always returns 28th of contribution month', function () {
        $member = User::factory()->member()->employed()->create();

        $contribution = Contribution::factory()
            ->forUser($member)
            ->forMonth(2025, 1) // January 2025
            ->create();

        expect($contribution->due_date->day)->toBe(28);
        expect($contribution->due_date->month)->toBe(1);
        expect($contribution->due_date->year)->toBe(2025);
    });

    it('due_date works for February in non-leap year', function () {
        $contribution = Contribution::factory()
            ->forMonth(2025, 2) // February 2025 (non-leap year)
            ->create();

        expect($contribution->due_date->day)->toBe(28);
        expect($contribution->due_date->month)->toBe(2);
    });

    it('due_date works for February in leap year', function () {
        $contribution = Contribution::factory()
            ->forMonth(2024, 2) // February 2024 (leap year)
            ->create();

        expect($contribution->due_date->day)->toBe(28);
        expect($contribution->due_date->month)->toBe(2);
    });

    it('due_date works for different months', function () {
        $months = [
            ['month' => 3, 'name' => 'March'],
            ['month' => 6, 'name' => 'June'],
            ['month' => 9, 'name' => 'September'],
            ['month' => 12, 'name' => 'December'],
        ];

        foreach ($months as $monthData) {
            $contribution = Contribution::factory()
                ->forMonth(2025, $monthData['month'])
                ->create();

            expect($contribution->due_date->day)->toBe(28);
            expect($contribution->due_date->month)->toBe($monthData['month']);
        }
    });

    it('isOverdue returns true when current date is after due date', function () {
        Carbon::setTestNow(Carbon::create(2025, 1, 29)); // January 29, 2025

        $contribution = Contribution::factory()
            ->forMonth(2025, 1) // Due January 28, 2025
            ->create();

        expect($contribution->isOverdue())->toBeTrue();

        Carbon::setTestNow(); // Reset
    });

    it('isOverdue returns false when current date is before due date', function () {
        Carbon::setTestNow(Carbon::create(2025, 1, 27)); // January 27, 2025

        $contribution = Contribution::factory()
            ->forMonth(2025, 1) // Due January 28, 2025
            ->create();

        expect($contribution->isOverdue())->toBeFalse();

        Carbon::setTestNow(); // Reset
    });

    it('isOverdue returns false on exact due date', function () {
        Carbon::setTestNow(Carbon::create(2025, 1, 28, 0, 0, 0)); // January 28, 2025 midnight

        $contribution = Contribution::factory()
            ->forMonth(2025, 1) // Due January 28, 2025
            ->create();

        // On the due date at midnight, the due_date datetime would be the same
        // so now() is not greater than due_date
        expect($contribution->isOverdue())->toBeFalse();

        Carbon::setTestNow(); // Reset
    });

    it('period_label returns formatted month and year', function () {
        $contribution = Contribution::factory()
            ->forMonth(2025, 12)
            ->create();

        expect($contribution->period_label)->toBe('December 2025');
    });
});
