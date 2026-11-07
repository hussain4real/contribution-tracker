<?php

declare(strict_types=1);

use App\Enums\AiActionKind;
use App\Models\Contribution;
use App\Models\Expense;
use App\Models\FamilyGoal;
use App\Models\FundAdjustment;
use App\Models\Payment;
use App\Models\PaymentBatch;
use App\Services\AiActionDrafts;

it('resumes family setup and previews a member summary without creating ledger entries', function () {
    $family = createBrowserFamily();
    $admin = createBrowserAdmin($family);
    $page = loginBrowserAs($admin);
    $page->resize(390, 844);
    $page->navigate(route('family.onboarding'));
    $page->assertSee('Get your family ready');
    $page->click('Preview member view');
    $page->assertSee('Example member view');
    $page->assertSee('Preview only.');
    $page->assertNoJavaScriptErrors();
    $page->keys('[data-slot="dialog-close"]', 'Escape');
    $page->refresh()->assertSee('Get your family ready');
    expect(Payment::count())->toBe(0)->and(Expense::count())->toBe(0);
});

it('creates edits traces and archives a visual goal on a narrow screen', function () {
    $family = createBrowserFamily();
    $admin = createBrowserAdmin($family);
    Expense::factory()->recordedBy($admin)->create(['amount' => 250, 'description' => 'Goal source expense', 'spent_at' => today()]);
    $page = loginBrowserAs($admin);
    $page->resize(390, 844)->navigate(route('family.goals.index'))
        ->assertSee('Money stays in the shared fund')
        ->click('New goal')->fill('#goal-name', 'Education reserve target')
        ->fill('#goal-target', '5000')->click('Create visual goal')
        ->assertSee('Education reserve target')->assertNoJavaScriptErrors();
    expect(FamilyGoal::sole()->target_amount)->toBe(5000);
    $page->click('View supporting entries')->assertSee('Goal source expense')->assertSee('Family financial activity');
    $page->navigate(route('family.goals.index'))->click('[aria-label="Edit goal Education reserve target"]')
        ->fill('#goal-name', 'School target')->click('Save changes')->assertSee('School target');
    $page->click('[aria-label="Archive goal School target"]')->assertDontSee('School target')
        ->click('Include archived goals')->assertSee('School target')
        ->click('[aria-label="Restore goal School target"]')->assertSee('School target')->assertNoJavaScriptErrors();
    expect(FamilyGoal::sole()->archived_at)->toBeNull()->and(Payment::count())->toBe(0)->and(Expense::count())->toBe(1);
});

it('records an AI expense draft after human review and reverses it without deleting the audit entry', function () {
    $family = createBrowserFamily();
    $admin = createBrowserAdmin($family);
    $page = loginBrowserAs($admin);
    $draft = app(AiActionDrafts::class)->create($admin, $family, AiActionKind::Expense, ['amount' => 750, 'description' => 'Human reviewed AI draft', 'spent_at' => today()->toDateString()]);
    $page->navigate($draft['review_url'])->assertSee('AI draft')
        ->assertValue('#amount', '750')->assertValue('#description', 'Human reviewed AI draft')
        ->assertNoJavaScriptErrors();
    expect(Expense::count())->toBe(0);
    $page->click('button[type="submit"]')->assertSee('Human reviewed AI draft')->assertNoJavaScriptErrors();
    expect(Expense::count())->toBe(1);
    $page->navigate($draft['review_url'])->click('button[type="submit"]')->assertSee('Human reviewed AI draft');
    expect(Expense::count())->toBe(1);
    $page->resize(390, 844)->click('button:text-is("Reverse")')->assertSee('Reverse expense')
        ->fill('#expense-reversal-reason', 'Synthetic draft verification')
        ->click('button:text-is("Reverse expense")')->assertSee('Synthetic draft verification')
        ->assertNoJavaScriptErrors();
    expect(Expense::sole()->isReversed())->toBeTrue()
        ->and(Expense::query()->effective()->count())->toBe(0);
});

it('keeps a recorded AI adjustment draft idempotent when reopened in the same tab', function () {
    $family = createBrowserFamily();
    $admin = createBrowserAdmin($family);
    $page = loginBrowserAs($admin);
    $draft = app(AiActionDrafts::class)->create($admin, $family, AiActionKind::Adjustment, [
        'amount' => 750, 'description' => 'Reviewed adjustment draft', 'recorded_at' => today()->toDateString(),
    ]);
    $page->navigate($draft['review_url'])->assertSee('AI draft')->assertValue('#amount', '750');
    expect(FundAdjustment::count())->toBe(0);
    $page->click('form button[type="submit"]')->assertSee('Reviewed adjustment draft')->assertDontSee('AI draft');
    expect(FundAdjustment::count())->toBe(1);
    $page->navigate($draft['review_url'])->assertSee('AI draft')
        ->assertDontSee('We could not confirm whether the adjustment was recorded.')
        ->click('form button[type="submit"]')->assertSee('Reviewed adjustment draft')->assertNoJavaScriptErrors();
    expect(FundAdjustment::count())->toBe(1);
    $page->click('Record Adjustment');
    fillBrowserFieldWithoutChange($page, '#amount', '100');
    fillBrowserFieldWithoutChange($page, '#description', 'Separate manual adjustment');
    $page->click('form button[type="submit"]')->assertSee('Separate manual adjustment')->assertNoJavaScriptErrors();
    expect(FundAdjustment::count())->toBe(2);
});

it('opens an AI payment draft at allocation review without posting a receipt', function () {
    $family = createBrowserFamily();
    $admin = createBrowserAdmin($family);
    $member = createBrowserMember($family, ['name' => 'Draft member']);
    $contribution = Contribution::factory()->forUser($member)->currentMonth()->create(['expected_amount' => 4000]);
    $page = loginBrowserAs($admin);
    $draft = app(AiActionDrafts::class)->create($admin, $family, AiActionKind::Payment, [
        'member_id' => $member->id, 'member' => $member->name, 'amount' => 1250,
        'paid_at' => today()->toDateString(), 'notes' => 'Prepared for human review',
        'target_year' => now()->year, 'target_month' => now()->month,
    ]);
    $page->navigate($draft['review_url'])->assertSee('AI draft')
        ->assertValue('#amount', '1250')->assertValue('#notes', 'Prepared for human review')
        ->click('Review payment')->assertSee($contribution->period_label.':')->assertNoJavaScriptErrors();
    expect($page->script('() => document.activeElement?.id'))->toBe('review-heading');
    expect(Payment::count())->toBe(0)->and(PaymentBatch::count())->toBe(0);
});
