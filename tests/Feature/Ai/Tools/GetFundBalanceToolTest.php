<?php

use App\Ai\Tools\GetFundBalance;
use App\Models\Contribution;
use App\Models\Expense;
use App\Models\Family;
use App\Models\FundAdjustment;
use App\Models\Payment;
use App\Models\User;
use Laravel\Ai\Tools\Request;

beforeEach(function () {
    $this->family = Family::factory()->create(['currency' => '₦']);
    $this->user = User::factory()->create(['family_id' => $this->family->id]);
});

it('returns error when user has no family', function () {
    $userWithoutFamily = User::factory()->create(['family_id' => null]);

    $tool = new GetFundBalance($userWithoutFamily);
    $result = json_decode($tool->handle(new Request), true);

    expect($result)->toHaveKey('error', 'User is not associated with a family.');
});

it('calculates fund balance as payments plus adjustments minus expenses', function () {
    $contribution = Contribution::factory()->create([
        'family_id' => $this->family->id,
        'user_id' => $this->user->id,
    ]);

    Payment::factory()->create(['contribution_id' => $contribution->id, 'amount' => 10000]);
    FundAdjustment::factory()->create(['family_id' => $this->family->id, 'amount' => 5000]);
    Expense::factory()->create(['family_id' => $this->family->id, 'amount' => 3000, 'spent_at' => now()]);

    $tool = new GetFundBalance($this->user);
    $result = json_decode($tool->handle(new Request), true);

    expect($result['fund_balance'])->toBe(12000) // 10000 + 5000 - 3000
        ->and($result['currency'])->toBe('₦');
});

it('returns zero balance when no financial data exists', function () {
    $tool = new GetFundBalance($this->user);
    $result = json_decode($tool->handle(new Request), true);

    expect($result['fund_balance'])->toBe(0);
});

it('can return negative balance when expenses exceed income', function () {
    Expense::factory()->create(['family_id' => $this->family->id, 'amount' => 5000, 'spent_at' => now()]);

    $tool = new GetFundBalance($this->user);
    $result = json_decode($tool->handle(new Request), true);

    expect($result['fund_balance'])->toBe(-5000);
});

it('does not include breakdown by default', function () {
    $tool = new GetFundBalance($this->user);
    $result = json_decode($tool->handle(new Request), true);

    expect($result)->not->toHaveKey('breakdown')
        ->and($result)->not->toHaveKey('recent_adjustments');
});

it('includes breakdown when requested', function () {
    $contribution = Contribution::factory()->create([
        'family_id' => $this->family->id,
        'user_id' => $this->user->id,
    ]);

    Payment::factory()->create(['contribution_id' => $contribution->id, 'amount' => 8000]);
    FundAdjustment::factory()->create(['family_id' => $this->family->id, 'amount' => 2000]);
    Expense::factory()->create(['family_id' => $this->family->id, 'amount' => 1000, 'spent_at' => now()]);

    $tool = new GetFundBalance($this->user);
    $result = json_decode($tool->handle(new Request(['include_breakdown' => true])), true);

    expect($result['breakdown'])
        ->toHaveKey('total_payments', 8000)
        ->toHaveKey('total_fund_adjustments', 2000)
        ->toHaveKey('total_expenses', 1000);

    expect($result['recent_adjustments'])->toHaveCount(1);
});

it('does not include data from other families', function () {
    $otherFamily = Family::factory()->create();
    $otherUser = User::factory()->create(['family_id' => $otherFamily->id]);

    $contribution = Contribution::factory()->create([
        'family_id' => $otherFamily->id,
        'user_id' => $otherUser->id,
    ]);

    Payment::factory()->create(['contribution_id' => $contribution->id, 'amount' => 50000]);
    FundAdjustment::factory()->create(['family_id' => $otherFamily->id, 'amount' => 20000]);

    $tool = new GetFundBalance($this->user);
    $result = json_decode($tool->handle(new Request), true);

    expect($result['fund_balance'])->toBe(0);
});

it('limits recent adjustments to 10 in breakdown', function () {
    for ($i = 0; $i < 15; $i++) {
        FundAdjustment::factory()->create([
            'family_id' => $this->family->id,
            'amount' => 1000,
        ]);
    }

    $tool = new GetFundBalance($this->user);
    $result = json_decode($tool->handle(new Request(['include_breakdown' => true])), true);

    expect($result['recent_adjustments'])->toHaveCount(10);
});
