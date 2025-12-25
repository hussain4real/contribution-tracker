<?php

use App\Enums\MemberCategory;
use App\Models\Contribution;
use App\Models\User;

/**
 * T059a [US3] Feature test for category change taking effect next month (FR-017)
 *
 * FR-017: Category changes SHOULD take effect from the next month
 * - Current month contribution keeps the old expected amount
 * - Next month contribution uses the new category amount
 */
describe('Category Change Next Month', function () {
    beforeEach(function () {
        $this->superAdmin = User::factory()->superAdmin()->create();
        $this->member = User::factory()->member()->employed()->create();
    });

    it('existing current month contribution keeps old amount after category change', function () {
        // Create a contribution for current month with employed amount (₦4,000)
        $contribution = Contribution::factory()
            ->forUser($this->member)
            ->currentMonth()
            ->employed()
            ->create();

        expect($contribution->expected_amount)->toBe(400000);

        // Change category to student
        $this->actingAs($this->superAdmin)
            ->put("/members/{$this->member->id}", [
                'name' => $this->member->name,
                'email' => $this->member->email,
                'category' => 'student',
                'role' => $this->member->role->value,
            ])
            ->assertRedirect();

        // The existing contribution should still have the old amount
        $contribution->refresh();
        expect($contribution->expected_amount)->toBe(400000); // Still ₦4,000
    });

    it('new contribution after category change uses new amount', function () {
        // Change category from employed to student
        $this->actingAs($this->superAdmin)
            ->put("/members/{$this->member->id}", [
                'name' => $this->member->name,
                'email' => $this->member->email,
                'category' => 'student',
                'role' => $this->member->role->value,
            ]);

        $this->member->refresh();
        expect($this->member->category)->toBe(MemberCategory::Student);

        // Create a contribution for next month
        $nextMonth = now()->addMonth();
        $contribution = Contribution::factory()
            ->forUser($this->member)
            ->forMonth($nextMonth->year, $nextMonth->month)
            ->create([
                'expected_amount' => $this->member->getMonthlyAmountInKobo(),
            ]);

        // Should use new category amount (₦1,000 for student)
        expect($contribution->expected_amount)->toBe(100000);
    });

    it('user model reflects new monthly amount immediately', function () {
        expect($this->member->getMonthlyAmountInKobo())->toBe(400000); // Employed

        $this->actingAs($this->superAdmin)
            ->put("/members/{$this->member->id}", [
                'name' => $this->member->name,
                'email' => $this->member->email,
                'category' => 'unemployed',
                'role' => $this->member->role->value,
            ]);

        $this->member->refresh();
        expect($this->member->getMonthlyAmountInKobo())->toBe(200000); // Unemployed
    });

    it('all category transitions work correctly', function () {
        $transitions = [
            ['from' => 'employed', 'to' => 'unemployed', 'amount' => 200000],
            ['from' => 'unemployed', 'to' => 'student', 'amount' => 100000],
            ['from' => 'student', 'to' => 'employed', 'amount' => 400000],
        ];

        foreach ($transitions as $transition) {
            $member = User::factory()->member()->create([
                'category' => MemberCategory::from($transition['from']),
            ]);

            $this->actingAs($this->superAdmin)
                ->put("/members/{$member->id}", [
                    'name' => $member->name,
                    'email' => $member->email,
                    'category' => $transition['to'],
                    'role' => $member->role->value,
                ]);

            $member->refresh();
            expect($member->getMonthlyAmountInKobo())->toBe($transition['amount']);
        }
    });
});
