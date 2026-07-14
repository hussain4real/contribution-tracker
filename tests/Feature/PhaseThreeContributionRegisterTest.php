<?php

declare(strict_types=1);

use App\Models\Contribution;
use App\Models\Family;
use App\Models\FamilyCategory;
use App\Models\Payment;
use App\Models\User;
use App\Services\FamilyContributionReviewService;
use Inertia\Testing\AssertableInertia;

/** @return array{Family, User, User, FamilyCategory} */
function phaseThreeRegisterFixture(): array
{
    $family = Family::factory()->create(['name' => 'Register Family']);
    $category = FamilyCategory::factory()->create([
        'family_id' => $family->id,
        'name' => 'Adults',
        'slug' => 'adults',
        'monthly_amount' => 5000,
    ]);
    $admin = User::factory()->admin()->create(['family_id' => $family->id]);
    $member = User::factory()->member()->create([
        'family_id' => $family->id,
        'name' => 'Amina Yusuf',
        'family_category_id' => $category->id,
    ]);

    return [$family, $admin, $member, $category];
}

it('serves a filtered stable contribution register with deferred shared totals', function () {
    [$family, $admin, $member, $category] = phaseThreeRegisterFixture();
    $paid = Contribution::factory()->forUser($member)->forMonth(2026, 1)->create([
        'family_id' => $family->id,
        'family_category_id' => $category->id,
        'category_name' => $category->name,
        'category_slug' => $category->slug,
        'expected_amount' => 5000,
        'due_date' => '2026-01-28',
    ]);
    Payment::factory()->forContribution($paid)->create(['amount' => 5000, 'paid_at' => '2026-01-20']);
    Contribution::factory()->forUser($member)->forMonth(2026, 2)->create([
        'family_id' => $family->id,
        'family_category_id' => $category->id,
        'category_name' => $category->name,
        'category_slug' => $category->slug,
        'expected_amount' => 5000,
        'due_date' => '2026-02-28',
    ]);

    $filters = [
        'date_from' => '2026-01-01',
        'date_to' => '2026-12-31',
        'member_id' => $member->id,
        'category' => 'adults',
        'status' => 'paid',
        'min_outstanding' => 0,
        'search' => 'amina',
        'per_page' => 25,
    ];
    $service = app(FamilyContributionReviewService::class);

    expect($service->registerRows($family, $filters))->toHaveCount(1)
        ->and($service->registerSummary($family, $filters))->toMatchArray([
            'total_expected' => 5000,
            'total_collected' => 5000,
            'total_outstanding' => 0,
            'collection_rate' => 100.0,
            'record_count' => 1,
        ]);

    $this->actingAs($admin)
        ->get(route('contributions.index', ['current_family' => $family->slug, ...$filters]))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('Contributions/Index')
            ->where('filters.per_page', 25)
            ->has('contributions.data', 1)
            ->where('contributions.data.0.member_name', 'Amina Yusuf')
            ->where('contributions.data.0.status', 'paid')
            ->where('contributions.per_page', 25));
});

it('supports unpaid overdue outstanding and tenant filters without leaking rows', function () {
    [$family, $admin, $member, $category] = phaseThreeRegisterFixture();
    $otherFamily = Family::factory()->create();
    $outsider = User::factory()->member()->create(['family_id' => $otherFamily->id]);
    Contribution::factory()->forUser($member)->forMonth(2025, 1)->create([
        'family_id' => $family->id,
        'category_slug' => $category->slug,
        'category_name' => $category->name,
        'expected_amount' => 7000,
        'due_date' => '2025-01-28',
    ]);
    Contribution::factory()->forUser($outsider)->forMonth(2025, 1)->create([
        'family_id' => $otherFamily->id,
        'expected_amount' => 9000,
        'due_date' => '2025-01-28',
    ]);

    $rows = app(FamilyContributionReviewService::class)->registerRows($family, [
        'date_from' => '2025-01-01',
        'date_to' => '2025-12-31',
        'status' => 'overdue',
        'min_outstanding' => 6000,
    ]);

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['member_id'])->toBe($member->id)
        ->and($rows[0]['outstanding_amount'])->toBe(7000)
        ->and($rows[0]['age_days'])->toBeGreaterThan(0);

    $this->actingAs($admin)
        ->get(route('contributions.index', ['current_family' => $family->slug, 'per_page' => 13]))
        ->assertSessionHasErrors('per_page');
});

it('keeps the complete register restricted to family officers', function () {
    [$family, , $member] = phaseThreeRegisterFixture();

    $this->actingAs($member)
        ->get(route('contributions.index', ['current_family' => $family->slug]))
        ->assertForbidden();
});
