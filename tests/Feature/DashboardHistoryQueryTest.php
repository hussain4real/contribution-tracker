<?php

declare(strict_types=1);

use App\Models\Contribution;
use App\Models\Family;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

it('renders growing contribution history without querying membership for each row', function (): void {
    $family = Family::factory()->create();
    $officer = User::factory()->financialSecretary()->create(['family_id' => $family->id]);
    $members = User::factory()->member()->employed()->count(4)->create(['family_id' => $family->id]);
    foreach ($members as $member) {
        Contribution::factory()->forUser($member)->currentMonth()->employed()->create(['due_date' => now()->addDay()]);
    }
    $this->actingAs($officer);
    DB::enableQueryLog();
    $this->get(route('dashboard', ['current_family' => $family->slug]))->assertOk();
    $baselineQueries = count(DB::getQueryLog());
    DB::disableQueryLog();
    foreach ($members as $member) {
        foreach (range(1, 11) as $month) {
            Contribution::factory()->forUser($member)->forMonth(2025, $month)->employed()->create();
        }
    }
    $additionalMembers = User::factory()->member()->employed()->count(8)->create(['family_id' => $family->id]);
    foreach ($additionalMembers as $member) {
        Contribution::factory()->forUser($member)->currentMonth()->employed()->create(['due_date' => now()->addDay()]);
    }
    DB::flushQueryLog();
    DB::enableQueryLog();
    $this->get(route('dashboard', ['current_family' => $family->slug]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('member_statuses', 12)
            ->where('summary.total_members', 13)
            ->missing('overdue_members'));
    $historyQueries = count(DB::getQueryLog());
    DB::disableQueryLog();
    expect($historyQueries)->toBeLessThanOrEqual($baselineQueries + 6);
    $this->get(route('dashboard', ['current_family' => $family->slug]))
        ->assertInertia(fn (Assert $page) => $page->reloadOnly('overdue_members', fn (Assert $reload) => $reload
            ->has('overdue_members', 44)
            ->where('overdue_members.0.name', $members->sortBy('name')->first()?->name)));
});
