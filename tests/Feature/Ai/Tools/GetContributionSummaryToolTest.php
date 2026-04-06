<?php

use App\Ai\Tools\GetContributionSummary;
use App\Models\Contribution;
use App\Models\Family;
use App\Models\Payment;
use App\Models\User;
use Laravel\Ai\Tools\Request;

beforeEach(function () {
    $this->family = Family::factory()->create();
    $this->user = User::factory()->create(['family_id' => $this->family->id]);
});

it('returns contribution summary for current year by default', function () {
    $contribution = Contribution::factory()->create([
        'family_id' => $this->family->id,
        'user_id' => $this->user->id,
        'year' => now()->year,
        'month' => now()->month,
        'expected_amount' => 4000,
    ]);

    Payment::factory()->create([
        'contribution_id' => $contribution->id,
        'amount' => 2500,
    ]);

    $tool = new GetContributionSummary($this->user);
    $result = json_decode($tool->handle(new Request), true);

    expect($result)
        ->toHaveKey('period', 'Year '.now()->year)
        ->toHaveKey('total_expected', 4000)
        ->toHaveKey('total_paid', 2500)
        ->toHaveKey('outstanding', 1500)
        ->toHaveKey('collection_rate', 62.5)
        ->toHaveKey('member_count', 1);
});

it('filters contributions by specific year and month', function () {
    Contribution::factory()->create([
        'family_id' => $this->family->id,
        'user_id' => $this->user->id,
        'year' => 2025,
        'month' => 3,
        'expected_amount' => 4000,
    ]);

    Contribution::factory()->create([
        'family_id' => $this->family->id,
        'user_id' => $this->user->id,
        'year' => 2025,
        'month' => 4,
        'expected_amount' => 4000,
    ]);

    $tool = new GetContributionSummary($this->user);
    $result = json_decode($tool->handle(new Request(['year' => 2025, 'month' => 3])), true);

    expect($result)
        ->toHaveKey('period', 'March 2025')
        ->toHaveKey('total_expected', 4000)
        ->toHaveKey('member_count', 1);
});

it('returns zero values when no contributions exist', function () {
    $tool = new GetContributionSummary($this->user);
    $result = json_decode($tool->handle(new Request), true);

    expect($result)
        ->toHaveKey('total_expected', 0)
        ->toHaveKey('total_paid', 0)
        ->toHaveKey('outstanding', 0)
        ->toHaveKey('collection_rate', 0)
        ->toHaveKey('member_count', 0);
});

it('excludes archived members from the summary', function () {
    $archivedUser = User::factory()->create([
        'family_id' => $this->family->id,
        'archived_at' => now(),
    ]);

    Contribution::factory()->create([
        'family_id' => $this->family->id,
        'user_id' => $archivedUser->id,
        'year' => now()->year,
        'expected_amount' => 4000,
    ]);

    $tool = new GetContributionSummary($this->user);
    $result = json_decode($tool->handle(new Request), true);

    expect($result['member_count'])->toBe(0);
});

it('does not include contributions from other families', function () {
    $otherFamily = Family::factory()->create();
    $otherUser = User::factory()->create(['family_id' => $otherFamily->id]);

    Contribution::factory()->create([
        'family_id' => $otherFamily->id,
        'user_id' => $otherUser->id,
        'year' => now()->year,
        'expected_amount' => 5000,
    ]);

    $tool = new GetContributionSummary($this->user);
    $result = json_decode($tool->handle(new Request), true);

    expect($result['total_expected'])->toBe(0);
});

it('includes per-member breakdown with payment details', function () {
    $contribution = Contribution::factory()->create([
        'family_id' => $this->family->id,
        'user_id' => $this->user->id,
        'year' => now()->year,
        'expected_amount' => 4000,
    ]);

    Payment::factory()->create(['contribution_id' => $contribution->id, 'amount' => 1000]);
    Payment::factory()->create(['contribution_id' => $contribution->id, 'amount' => 1500]);

    $tool = new GetContributionSummary($this->user);
    $result = json_decode($tool->handle(new Request), true);

    expect($result['members'])->toHaveCount(1);
    expect($result['members'][0])
        ->toHaveKey('name', $this->user->name)
        ->toHaveKey('expected', 4000)
        ->toHaveKey('paid', 2500)
        ->toHaveKey('outstanding', 1500);
});
