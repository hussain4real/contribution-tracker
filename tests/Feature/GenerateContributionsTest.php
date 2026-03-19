<?php

use App\Models\Contribution;
use App\Models\Family;
use App\Models\User;

describe('Generate Monthly Contributions Command', function () {
    it('creates contribution records for all active members', function () {
        $family = Family::factory()->create(['due_day' => 28]);
        $member1 = User::factory()->member()->employed()->create(['family_id' => $family->id]);
        $member2 = User::factory()->member()->student()->create(['family_id' => $family->id]);

        $this->artisan('contributions:generate', ['--family' => $family->id])
            ->assertSuccessful();

        expect(Contribution::where('family_id', $family->id)->count())->toBe(2);

        $contribution = Contribution::where('user_id', $member1->id)->first();
        expect($contribution->year)->toBe(now()->year)
            ->and($contribution->month)->toBe(now()->month)
            ->and($contribution->expected_amount)->toBe($member1->getMonthlyAmount());
    });

    it('skips members that already have contributions for the month', function () {
        $family = Family::factory()->create();
        $member = User::factory()->member()->employed()->create(['family_id' => $family->id]);

        Contribution::factory()->forUser($member)->currentMonth()->create();

        $this->artisan('contributions:generate', ['--family' => $family->id])
            ->assertSuccessful();

        expect(Contribution::where('user_id', $member->id)->count())->toBe(1);
    });

    it('skips archived members', function () {
        $family = Family::factory()->create();
        User::factory()->member()->employed()->create([
            'family_id' => $family->id,
            'archived_at' => now(),
        ]);

        $this->artisan('contributions:generate', ['--family' => $family->id])
            ->assertSuccessful();

        expect(Contribution::where('family_id', $family->id)->count())->toBe(0);
    });

    it('skips members without a category', function () {
        $family = Family::factory()->create();
        User::factory()->create([
            'family_id' => $family->id,
            'category' => null,
        ]);

        $this->artisan('contributions:generate', ['--family' => $family->id])
            ->assertSuccessful();

        expect(Contribution::where('family_id', $family->id)->count())->toBe(0);
    });

    it('generates for a specific month and year', function () {
        $family = Family::factory()->create();
        User::factory()->member()->employed()->create(['family_id' => $family->id]);

        $this->artisan('contributions:generate', [
            '--family' => $family->id,
            '--year' => 2025,
            '--month' => 6,
        ])->assertSuccessful();

        $contribution = Contribution::where('family_id', $family->id)->first();
        expect($contribution->year)->toBe(2025)
            ->and($contribution->month)->toBe(6);
    });

    it('uses family due_day for the due date', function () {
        $family = Family::factory()->create(['due_day' => 15]);
        User::factory()->member()->employed()->create(['family_id' => $family->id]);

        $this->artisan('contributions:generate', ['--family' => $family->id])
            ->assertSuccessful();

        $contribution = Contribution::where('family_id', $family->id)->first();
        expect($contribution->due_date->day)->toBe(15);
    });

    it('generates for all families when no family specified', function () {
        $family1 = Family::factory()->create();
        $family2 = Family::factory()->create();
        User::factory()->member()->employed()->create(['family_id' => $family1->id]);
        User::factory()->member()->employed()->create(['family_id' => $family2->id]);

        $this->artisan('contributions:generate')
            ->assertSuccessful();

        expect(Contribution::count())->toBe(2);
    });
});

describe('Generate Contributions via UI', function () {
    it('allows admin to generate contributions', function () {
        $family = Family::factory()->create();
        $admin = User::factory()->admin()->create(['family_id' => $family->id]);
        User::factory()->member()->employed()->create(['family_id' => $family->id]);

        $this->actingAs($admin)
            ->post('/contributions/generate')
            ->assertRedirect();

        expect(Contribution::where('family_id', $family->id)->count())->toBe(1);
    });

    it('prevents members from generating contributions', function () {
        $family = Family::factory()->create();
        $member = User::factory()->member()->employed()->create(['family_id' => $family->id]);

        $this->actingAs($member)
            ->post('/contributions/generate')
            ->assertForbidden();

        expect(Contribution::where('family_id', $family->id)->count())->toBe(0);
    });

    it('returns success flash message', function () {
        $family = Family::factory()->create();
        $admin = User::factory()->admin()->create(['family_id' => $family->id]);
        User::factory()->member()->student()->create(['family_id' => $family->id]);

        $this->actingAs($admin)
            ->post('/contributions/generate')
            ->assertRedirect()
            ->assertSessionHas('success');
    });
});
