<?php

use App\Models\Contribution;
use App\Models\Family;
use App\Models\User;

test('guests are redirected away from dashboard', function () {
    $response = $this->get(route('dashboard'));
    $response->assertRedirect();
});

test('authenticated users can visit the dashboard', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $response = $this->get(route('dashboard'));
    $response->assertStatus(200);
});

test('admin dashboard includes overdue_members with overdue contribution details', function () {
    $family = Family::factory()->create();
    $admin = User::factory()->admin()->create(['family_id' => $family->id]);
    $member = User::factory()->member()->create(['family_id' => $family->id]);

    // Create a current month contribution (not overdue)
    Contribution::factory()->forUser($member)->currentMonth()->create();

    // Create a past month contribution that is overdue (unpaid, past due date)
    $overdueContribution = Contribution::factory()->forUser($member)->forMonth(2025, 1)->create();

    $response = $this->actingAs($admin)->get(route('dashboard'));

    $response->assertSuccessful();
    $response->assertInertia(fn ($page) => $page
        ->component('Dashboard/Index')
        ->has('overdue_members')
        ->where('overdue_members.0.name', $member->name)
        ->where('overdue_members.0.contribution_id', $overdueContribution->id)
        ->where('overdue_members.0.balance', $overdueContribution->expected_amount)
    );
});

test('admin dashboard overdue_members is empty when no overdue contributions exist', function () {
    $family = Family::factory()->create();
    $admin = User::factory()->admin()->create(['family_id' => $family->id]);
    $member = User::factory()->member()->create(['family_id' => $family->id]);

    // Only current month contribution (may not be overdue depending on DUE_DAY)
    Contribution::factory()->forUser($member)->nextMonth()->create();

    $response = $this->actingAs($admin)->get(route('dashboard'));

    $response->assertSuccessful();
    $response->assertInertia(fn ($page) => $page
        ->component('Dashboard/Index')
        ->has('overdue_members', 0)
    );
});

test('member_statuses accrued_balance sums outstanding across all months', function () {
    $family = Family::factory()->create();
    $admin = User::factory()->admin()->create(['family_id' => $family->id]);
    $member = User::factory()->member()->create(['family_id' => $family->id]);

    // Current month contribution (unpaid) — ₦4,000
    Contribution::factory()->forUser($member)->currentMonth()->create();

    // Past month contribution (overdue, unpaid) — ₦4,000
    Contribution::factory()->forUser($member)->forMonth(2025, 1)->create();

    $response = $this->actingAs($admin)->get(route('dashboard'));

    $response->assertSuccessful();
    $response->assertInertia(fn ($page) => $page
        ->component('Dashboard/Index')
        ->has('member_statuses', 1)
        ->where('member_statuses.0.accrued_balance', $member->getMonthlyAmount() * 2)
        ->where('member_statuses.0.current_month_balance', $member->getMonthlyAmount())
    );
});
