<?php

declare(strict_types=1);

use App\Models\Expense;
use App\Models\ExpenseBudget;
use App\Models\ExpenseCategory;
use App\Models\ExpenseRequest;
use App\Models\PlatformPlan;

it('sets a monthly budget on mobile and traces over-budget actual spending', function () {
    $family = createBrowserFamily();
    $admin = createBrowserAdmin($family);
    $page = loginBrowserAs($admin);
    $page->resize(390, 844)->navigate(route('expense-budgets.index'));
    $page->assertSee('Expense budgets');
    $page->fill('Category name', 'Welfare');
    $page->click('Add category');
    $page->assertSee('Set Welfare budget');
    $page->click('Set Welfare budget');
    $page->fill('#budget-amount', '1000');
    $page->click('Save budget');
    $page->assertSee('Monthly budget saved.');
    expect(ExpenseBudget::sole()->amount)->toBe(1000);
    $category = ExpenseCategory::sole();
    $page->navigate(route('expenses.create'))->select('#expense-category', (string) $category->id);
    fillBrowserFieldWithoutChange($page, '[name="amount"]', '1500');
    fillBrowserFieldWithoutChange($page, 'input[name="description"]', 'Synthetic budget support');
    $page->assertSee('Over monthly budget by')->assertSee('Advisory only')
        ->click('button[type="submit"]')->assertSee('Synthetic budget support');
    expect(Expense::sole()->expense_category_id)->toBe($category->id);
    $page->click('Monthly expense budgets')->assertSee('Over budget by')->screenshot(filename: 'expense-budget-mobile')
        ->click('[aria-label="View Welfare actual spending"]')->assertSee('Synthetic budget support')
        ->click('a[href*="entry='.Expense::sole()->id.'"]')->assertSee('Synthetic budget support')->assertNoJavaScriptErrors();
    $nextMonth = now()->startOfMonth()->addMonth();
    $page->click('Monthly expense budgets')->fill('#budget-month', $nextMonth->format('Y-m'))->click('View month')
        ->assertSee('Not set')->assertDontSee('Over budget by')->click('Set Welfare budget')
        ->fill('#budget-amount', '2000')->click('Save budget')->assertSee('Monthly budget saved.');
    expect(ExpenseBudget::where('month', $nextMonth->toDateString())->sole()->amount)->toBe(2000)
        ->and(ExpenseBudget::where('month', now()->startOfMonth()->toDateString())->sole()->amount)->toBe(1000);
});

it('warns the independent approver before paying an over-budget request', function () {
    $family = createBrowserFamily();
    $owner = createBrowserAdmin($family, ['email' => 'budget-owner@example.test']);
    $officer = createBrowserFinancialSecretary($family, ['email' => 'budget-officer@example.test']);
    $plan = PlatformPlan::query()->firstOrCreate(['slug' => 'organization'], ['name' => 'Organization', 'price' => 1000, 'features' => [], 'is_active' => true]);
    $family->update(['platform_plan_id' => $plan->id, 'subscription_status' => 'active', 'expense_approval_settings' => ['enabled' => true, 'threshold' => 1, 'approvers' => 1]]);
    $category = ExpenseCategory::factory()->create(['family_id' => $family->id, 'name' => 'Welfare']);
    ExpenseBudget::factory()->create(['family_id' => $family->id, 'expense_category_id' => $category->id, 'amount' => 1000]);
    $page = loginBrowserAs($officer)->navigate(route('expense-requests.index'));
    $page->select('select[class]', (string) $category->id)->fill('Amount', '1500')->fill('Description', 'Synthetic budget request')
        ->assertSee('Over monthly budget by')->click('Submit request')->assertSee('Synthetic budget request');
    $request = ExpenseRequest::sole();
    $reviewer = loginBrowserAs($owner)->navigate(route('expense-requests.index'));
    $reviewer->assertSee('Over monthly budget by')->click('button[aria-label="Approve expense request '.$request->id.'"]')
        ->assertSee('1 of 1 independent approvals')->click('Monthly expense budgets')
        ->click('[aria-label="View Welfare committed spending"]')->assertSee('Synthetic budget request')
        ->click('a[href*="entry='.$request->id.'"]')->assertSee('Record payment for request #'.$request->id)->assertNoJavaScriptErrors();
    expect(Expense::count())->toBe(0);
});
