<?php

declare(strict_types=1);

use App\Models\Expense;

it('opens a monthly dashboard total and filters the supporting financial entries', function () {
    $family = createBrowserFamily();
    $admin = createBrowserAdmin($family);
    Expense::factory()->recordedBy($admin)->create([
        'description' => 'Accessible transport records',
        'amount' => 1250,
        'spent_at' => now()->toDateString(),
    ]);
    $page = loginBrowserAs($admin);
    $page->assertSee('Money movement')
        ->click('View transactions and activity')
        ->assertSee('Family financial activity')
        ->assertSee('Accessible transport records')
        ->assertSee('Expense Posted')
        ->assertNoJavaScriptErrors();
    $page->fill('Search descriptions or activity', 'absent record')
        ->click('Apply filters')
        ->assertSee('No transactions match these filters.')
        ->assertNoJavaScriptErrors();
});
