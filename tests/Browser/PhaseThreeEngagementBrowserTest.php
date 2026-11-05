<?php

declare(strict_types=1);

use App\Enums\AiActionKind;
use App\Models\Expense;
use App\Models\FamilyGoal;
use App\Models\Payment;
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
    $page->navigate(route('family.goals.index'))->click('Edit Education reserve target')
        ->fill('#goal-name', 'School target')->click('Save changes')->assertSee('School target');
    $page->click('Archive School target')->assertDontSee('School target')
        ->click('Include archived goals')->assertSee('School target')
        ->click('Restore School target')->assertSee('School target')->assertNoJavaScriptErrors();
    expect(FamilyGoal::sole()->archived_at)->toBeNull()->and(Payment::count())->toBe(0)->and(Expense::count())->toBe(1);
});

it('prefills an AI expense draft and records it only after a human submits the form', function () {
    $family = createBrowserFamily();
    $admin = createBrowserAdmin($family);
    $page = loginBrowserAs($admin);
    $draft = app(AiActionDrafts::class)->create($admin, $family, AiActionKind::Expense, ['amount' => 750, 'description' => 'Human reviewed AI draft', 'spent_at' => today()->toDateString()]);
    $page->navigate($draft['review_url'])->assertSee('AI draft')
        ->assertValue('#amount', '750')->assertValue('#description', 'Human reviewed AI draft')
        ->assertNoJavaScriptErrors();
    expect(Expense::count())->toBe(0);
    $page->click('Record Expense')->assertSee('Human reviewed AI draft')->assertNoJavaScriptErrors();
    expect(Expense::count())->toBe(1);
    $page->navigate($draft['review_url'])->click('Record Expense')->assertSee('Human reviewed AI draft');
    expect(Expense::count())->toBe(1);
});
