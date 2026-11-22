<?php

declare(strict_types=1);

use App\Models\Expense;
use App\Models\ExpenseBudget;
use App\Models\ExpenseCategory;
use App\Models\ExpenseRequest;
use App\Models\PlatformPlan;

it('manages a monthly template on mobile and lets an officer submit its budgeted draft', function () {
    $family = createBrowserFamily();
    $owner = createBrowserAdmin($family, ['email' => 'template-owner@example.test']);
    $officer = createBrowserFinancialSecretary($family, ['email' => 'template-officer@example.test']);
    $plan = PlatformPlan::query()->firstOrCreate(['slug' => 'organization'], ['name' => 'Organization', 'price' => 1000, 'features' => [], 'is_active' => true]);
    $family->update([
        'platform_plan_id' => $plan->id,
        'subscription_status' => 'active',
        'expense_approval_settings' => ['enabled' => true, 'threshold' => 1000, 'approvers' => 1],
    ]);
    $today = now('UTC');
    $category = ExpenseCategory::factory()->create(['family_id' => $family->id, 'name' => 'Subscriptions']);
    ExpenseBudget::factory()->create([
        'family_id' => $family->id, 'expense_category_id' => $category->id,
        'month' => $today->startOfMonth()->toDateString(), 'amount' => 1000,
    ]);

    $page = loginBrowserAs($owner)->resize(390, 844)->navigate(route('expense-requests.index'));
    $page->click('Recurring expense templates')->assertSee('Set up a monthly expense')
        ->fill('Description', 'Monthly internet')->fill('Amount', '1500')
        ->select('#expense-template-category', (string) $category->id)
        ->fill('Day of the month', (string) now('UTC')->day)
        ->click('Save template')->assertSee('Monthly internet')->assertSee('Next draft')
        ->click('button[aria-label="Pause Monthly internet"]')->assertSee('Paused');
    $this->artisan('expenses:generate-drafts')->assertSuccessful();
    expect(ExpenseRequest::count())->toBe(0);
    $page->click('button[aria-label="Resume Monthly internet"]')->assertSee('Next draft')->assertNoJavaScriptErrors();
    $this->artisan('expenses:generate-drafts')->assertSuccessful();
    $this->artisan('expenses:generate-drafts')->assertSuccessful();

    $draft = ExpenseRequest::sole();
    $review = loginBrowserAs($officer)->navigate(route('expense-requests.index', ['entry' => $draft->id]));
    $review->assertSee('Monthly internet')->assertSee('Over monthly budget by')
        ->assertSee('Recurring template')->assertDontSee('Record payment for request')
        ->click('button[aria-label="Submit expense draft '.$draft->id.'"]')
        ->assertSee('0 of 1 independent approvals')->assertDontSee('Record payment for request')->assertNoJavaScriptErrors();
    expect($draft->refresh()->requested_by)->toBe($officer->id)->and(Expense::count())->toBe(0);
});
