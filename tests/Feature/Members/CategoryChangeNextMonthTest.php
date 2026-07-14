<?php

declare(strict_types=1);

use App\Models\Contribution;
use App\Models\Family;
use App\Models\FamilyCategory;
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
        $this->family = Family::factory()->create();
        $this->admin = User::factory()->admin()->create(['family_id' => $this->family->id]);
        $this->member = User::factory()->member()->employed()->create(['family_id' => $this->family->id]);
        $this->employed = FamilyCategory::query()
            ->where('family_id', $this->family->id)
            ->where('slug', 'employed')
            ->firstOrFail();
        $this->unemployed = FamilyCategory::factory()->create([
            'family_id' => $this->family->id,
            'name' => 'Unemployed',
            'slug' => 'unemployed',
            'monthly_amount' => 2000,
        ]);
        $this->student = FamilyCategory::factory()->create([
            'family_id' => $this->family->id,
            'name' => 'Student',
            'slug' => 'student',
            'monthly_amount' => 1000,
        ]);
    });

    it('existing current month contribution keeps old amount after category change', function () {
        // Create a contribution for current month with employed amount (₦4,000)
        $contribution = Contribution::factory()
            ->forUser($this->member)
            ->currentMonth()
            ->employed()
            ->create();

        expect($contribution->expected_amount)->toBe(4000);

        // Change category to student
        $this->actingAs($this->admin)
            ->put("/members/{$this->member->id}", [
                'display_name' => $this->member->name,
                'family_category_id' => $this->student->id,
                'role' => $this->member->role->value,
            ])
            ->assertRedirect();

        // The existing contribution should still have the old amount
        $contribution->refresh();
        expect($contribution->expected_amount)->toBe(4000); // Still ₦4,000
    });

    it('new contribution after category change uses new amount', function () {
        // Change category from employed to student
        $this->actingAs($this->admin)
            ->put("/members/{$this->member->id}", [
                'display_name' => $this->member->name,
                'family_category_id' => $this->student->id,
                'role' => $this->member->role->value,
            ]);

        $nextMonth = now()->addMonth();

        $this->artisan('contributions:generate', [
            '--family' => $this->family->id,
            '--year' => $nextMonth->year,
            '--month' => $nextMonth->month,
        ])->assertSuccessful();

        $contribution = Contribution::query()
            ->where('family_id', $this->family->id)
            ->where('user_id', $this->member->id)
            ->forMonth($nextMonth->year, $nextMonth->month)
            ->firstOrFail();

        // Should use new category amount (₦1,000 for student)
        expect($contribution->expected_amount)->toBe(1000);
    });

    it('user model reflects new monthly amount immediately', function () {
        expect($this->member->getMonthlyAmount())->toBe(4000); // Employed

        $this->actingAs($this->admin)
            ->put("/members/{$this->member->id}", [
                'display_name' => $this->member->name,
                'family_category_id' => $this->unemployed->id,
                'role' => $this->member->role->value,
            ]);

        $this->member->refresh();
        expect($this->member->getMonthlyAmount())->toBe(2000); // Unemployed
    });

    it('all category transitions work correctly', function () {
        $transitions = [
            ['from' => $this->employed, 'to' => $this->unemployed, 'amount' => 2000],
            ['from' => $this->unemployed, 'to' => $this->student, 'amount' => 1000],
            ['from' => $this->student, 'to' => $this->employed, 'amount' => 4000],
        ];

        foreach ($transitions as $transition) {
            $member = User::factory()->member()->create([
                'family_id' => $this->family->id,
                'family_category_id' => $transition['from']->id,
            ]);

            $this->actingAs($this->admin)
                ->put("/members/{$member->id}", [
                    'display_name' => $member->name,
                    'family_category_id' => $transition['to']->id,
                    'role' => $member->role->value,
                    'effective_immediately' => true,
                ]);

            $member->refresh();
            expect($member->getMonthlyAmount())->toBe($transition['amount']);
        }
    });
});
