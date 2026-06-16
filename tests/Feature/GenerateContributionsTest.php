<?php

declare(strict_types=1);

use App\Enums\MemberCategory;
use App\Enums\Role;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Contribution;
use App\Models\Family;
use App\Models\FamilyCategory;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;

describe('Generate Monthly Contributions Command', function () {
    it('creates contribution records for all active members', function () {
        $family = Family::factory()->create(['due_day' => 28]);
        $member1 = User::factory()->member()->employed()->create(['family_id' => $family->id]);
        $member2 = User::factory()->member()->student()->create(['family_id' => $family->id]);

        $this->artisan('contributions:generate', ['--family' => $family->id])
            ->assertSuccessful();

        expect(Contribution::where('family_id', $family->id)->count())->toBe(2);

        $contribution = Contribution::where('user_id', $member1->id)->firstOrFail();
        expect($contribution->year)->toBe(now()->year)
            ->and($contribution->month)->toBe(now()->month)
            ->and($contribution->expected_amount)->toBe($member1->getMonthlyAmount());
    });

    it('creates contributions for members assigned only to a family category', function () {
        $family = Family::factory()->create();
        $familyCategory = FamilyCategory::factory()->create([
            'family_id' => $family->id,
            'monthly_amount' => 7500,
        ]);
        $member = User::factory()->member()->nonPaying()->create([
            'family_id' => $family->id,
            'family_category_id' => $familyCategory->id,
        ]);

        $this->artisan('contributions:generate', [
            '--family' => $family->id,
            '--year' => 2026,
            '--month' => 5,
        ])->assertSuccessful();

        $contribution = Contribution::query()
            ->where('user_id', $member->id)
            ->where('year', 2026)
            ->where('month', 5)
            ->firstOrFail();

        expect($contribution->family_id)->toBe($family->id)
            ->and($contribution->expected_amount)->toBe($familyCategory->monthly_amount);
    });

    it('skips members that already have contributions for the month', function () {
        $family = Family::factory()->create();
        $member = User::factory()->member()->employed()->create(['family_id' => $family->id]);

        Contribution::factory()->forUser($member)->currentMonth()->create();

        $this->artisan('contributions:generate', ['--family' => $family->id])
            ->assertSuccessful();

        expect(Contribution::where('user_id', $member->id)->count())->toBe(1);
    });

    it('does not create duplicate contributions when run repeatedly', function () {
        $family = Family::factory()->create();
        $member = User::factory()->member()->employed()->create(['family_id' => $family->id]);

        $this->artisan('contributions:generate', [
            '--family' => $family->id,
            '--year' => 2026,
            '--month' => 5,
        ])->assertSuccessful();

        $this->artisan('contributions:generate', [
            '--family' => $family->id,
            '--year' => 2026,
            '--month' => 5,
        ])
            ->expectsOutput('Created 0 contributions, skipped 1 (already exist).')
            ->assertSuccessful();

        expect(Contribution::query()
            ->where('user_id', $member->id)
            ->where('year', 2026)
            ->where('month', 5)
            ->count())->toBe(1);
    });

    it('enforces one contribution per family member per month at the database level', function () {
        $family = Family::factory()->create();
        $member = User::factory()->member()->employed()->create(['family_id' => $family->id]);

        Contribution::factory()->forUser($member)->forMonth(2026, 5)->create();

        expect(fn () => Contribution::factory()->forUser($member)->forMonth(2026, 5)->create())
            ->toThrow(UniqueConstraintViolationException::class);
    });

    it('creates same-period contributions per family membership after a member switches families', function () {
        $primaryFamily = Family::factory()->create(['due_day' => 28]);
        $secondaryFamily = Family::factory()->create(['due_day' => 28]);
        $member = User::factory()->member()->employed()->create(['family_id' => $primaryFamily->id]);
        $member->ensureFamilyMembership($secondaryFamily, Role::Member, MemberCategory::Student);
        $member->forceFill([
            'current_family_id' => $secondaryFamily->id,
            'family_id' => $secondaryFamily->id,
            'role' => Role::Member,
            'category' => MemberCategory::Student,
        ])->save();

        $this->artisan('contributions:generate', [
            '--family' => $primaryFamily->id,
            '--year' => 2026,
            '--month' => 5,
        ])->assertSuccessful();

        $this->artisan('contributions:generate', [
            '--family' => $secondaryFamily->id,
            '--year' => 2026,
            '--month' => 5,
        ])->assertSuccessful();

        $primaryContribution = Contribution::query()
            ->where('family_id', $primaryFamily->id)
            ->where('user_id', $member->id)
            ->where('year', 2026)
            ->where('month', 5)
            ->firstOrFail();
        $secondaryContribution = Contribution::query()
            ->where('family_id', $secondaryFamily->id)
            ->where('user_id', $member->id)
            ->where('year', 2026)
            ->where('month', 5)
            ->firstOrFail();

        expect($primaryContribution->expected_amount)->toBe(MemberCategory::Employed->monthlyAmount())
            ->and($secondaryContribution->expected_amount)->toBe(MemberCategory::Student->monthlyAmount())
            ->and(Contribution::query()
                ->where('user_id', $member->id)
                ->where('year', 2026)
                ->where('month', 5)
                ->count())->toBe(2);
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

        $contribution = Contribution::where('family_id', $family->id)->firstOrFail();
        expect($contribution->year)->toBe(2025)
            ->and($contribution->month)->toBe(6);
    });

    it('rejects invalid month options without creating contributions', function (int|string $month) {
        $family = Family::factory()->create();
        User::factory()->member()->employed()->create(['family_id' => $family->id]);

        $this->artisan('contributions:generate', [
            '--family' => $family->id,
            '--year' => 2026,
            '--month' => $month,
        ])
            ->expectsOutput('The --month option must be an integer between 1 and 12.')
            ->assertFailed();

        expect(Contribution::where('family_id', $family->id)->count())->toBe(0);
    })->with([
        'overflow month' => 13,
        'zero month' => 0,
        'non-numeric month' => 'abc',
    ]);

    it('uses family due_day for the due date', function () {
        $family = Family::factory()->create(['due_day' => 15]);
        User::factory()->member()->employed()->create(['family_id' => $family->id]);

        $this->artisan('contributions:generate', ['--family' => $family->id])
            ->assertSuccessful();

        $contribution = Contribution::where('family_id', $family->id)->firstOrFail();
        expect($contribution->due_date->day)->toBe(15);
    });

    it('clamps family due day to the last day of shorter months', function () {
        $family = Family::factory()->create(['due_day' => 30]);
        User::factory()->member()->employed()->create(['family_id' => $family->id]);

        $this->artisan('contributions:generate', [
            '--family' => $family->id,
            '--year' => 2027,
            '--month' => 2,
        ])->assertSuccessful();

        $contribution = Contribution::where('family_id', $family->id)->firstOrFail();

        expect($contribution->due_date->toDateString())->toBe('2027-02-28');
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
    it('shows the contributions index page', function () {
        config([
            'inertia.pages.ensure_pages_exist' => false,
            'inertia.testing.ensure_pages_exist' => false,
        ]);

        $admin = User::factory()->admin()->create();
        $version = app(HandleInertiaRequests::class)->version(request());

        $this->actingAs($admin)
            ->withHeaders([
                'X-Inertia' => 'true',
                'X-Inertia-Version' => $version,
            ])
            ->get(route('contributions.index'))
            ->assertSuccessful()
            ->assertJsonPath('component', 'Contributions/Index');
    });

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
