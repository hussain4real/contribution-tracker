<?php

use App\Ai\Tools\GetExpenseSummary;
use App\Models\Expense;
use App\Models\Family;
use App\Models\User;
use Laravel\Ai\Tools\Request;

beforeEach(function () {
    $this->family = Family::factory()->create();
    $this->user = User::factory()->create(['family_id' => $this->family->id]);
});

it('returns expense summary for current month by default', function () {
    Expense::factory()->create([
        'family_id' => $this->family->id,
        'amount' => 5000,
        'spent_at' => now(),
    ]);

    Expense::factory()->create([
        'family_id' => $this->family->id,
        'amount' => 3000,
        'spent_at' => now(),
    ]);

    $tool = new GetExpenseSummary($this->user);
    $result = json_decode($tool->handle(new Request), true);

    expect($result)
        ->toHaveKey('total_amount', 8000)
        ->toHaveKey('expense_count', 2)
        ->and($result['expenses'])->toHaveCount(2);
});

it('filters expenses by date range', function () {
    Expense::factory()->create([
        'family_id' => $this->family->id,
        'amount' => 5000,
        'spent_at' => '2025-06-15',
    ]);

    Expense::factory()->create([
        'family_id' => $this->family->id,
        'amount' => 3000,
        'spent_at' => '2025-07-10',
    ]);

    $tool = new GetExpenseSummary($this->user);
    $result = json_decode($tool->handle(new Request([
        'start_date' => '2025-06-01',
        'end_date' => '2025-06-30',
    ])), true);

    expect($result)
        ->toHaveKey('total_amount', 5000)
        ->toHaveKey('expense_count', 1);
});

it('returns zero values when no expenses exist', function () {
    $tool = new GetExpenseSummary($this->user);
    $result = json_decode($tool->handle(new Request), true);

    expect($result)
        ->toHaveKey('total_amount', 0)
        ->toHaveKey('expense_count', 0)
        ->and($result['expenses'])->toBeEmpty();
});

it('does not include expenses from other families', function () {
    $otherFamily = Family::factory()->create();

    Expense::factory()->create([
        'family_id' => $otherFamily->id,
        'amount' => 10000,
        'spent_at' => now(),
    ]);

    $tool = new GetExpenseSummary($this->user);
    $result = json_decode($tool->handle(new Request), true);

    expect($result['total_amount'])->toBe(0);
});

it('computes total from full result set not limited rows', function () {
    for ($i = 0; $i < 55; $i++) {
        Expense::factory()->create([
            'family_id' => $this->family->id,
            'amount' => 100,
            'spent_at' => now(),
        ]);
    }

    $tool = new GetExpenseSummary($this->user);
    $result = json_decode($tool->handle(new Request), true);

    expect($result['total_amount'])->toBe(5500)
        ->and($result['expense_count'])->toBe(55)
        ->and($result['expenses'])->toHaveCount(50);
});

it('includes recorder name in expense entries', function () {
    $recorder = User::factory()->create(['family_id' => $this->family->id, 'name' => 'Jane Admin']);

    Expense::factory()->create([
        'family_id' => $this->family->id,
        'amount' => 2000,
        'spent_at' => now(),
        'recorded_by' => $recorder->id,
    ]);

    $tool = new GetExpenseSummary($this->user);
    $result = json_decode($tool->handle(new Request), true);

    expect($result['expenses'][0]['recorded_by'])->toBe('Jane Admin');
});
