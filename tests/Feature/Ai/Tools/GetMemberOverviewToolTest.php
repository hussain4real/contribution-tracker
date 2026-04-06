<?php

use App\Ai\Tools\GetMemberOverview;
use App\Models\Contribution;
use App\Models\Family;
use App\Models\Payment;
use App\Models\User;
use Laravel\Ai\Tools\Request;

beforeEach(function () {
    $this->family = Family::factory()->create();
    $this->user = User::factory()->create(['family_id' => $this->family->id]);
});

it('returns overview of active family members', function () {
    User::factory()->create(['family_id' => $this->family->id, 'name' => 'Ali']);
    User::factory()->create(['family_id' => $this->family->id, 'name' => 'Bala']);

    $tool = new GetMemberOverview($this->user);
    $result = json_decode($tool->handle(new Request), true);

    expect($result['total_members'])->toBe(3) // includes $this->user
        ->and($result)->toHaveKey('current_period', now()->format('F Y'))
        ->and($result['members'])->toHaveCount(3);
});

it('excludes archived members', function () {
    User::factory()->create([
        'family_id' => $this->family->id,
        'archived_at' => now(),
    ]);

    $tool = new GetMemberOverview($this->user);
    $result = json_decode($tool->handle(new Request), true);

    expect($result['total_members'])->toBe(1);
});

it('includes current month payment status per member', function () {
    $contribution = Contribution::factory()->create([
        'family_id' => $this->family->id,
        'user_id' => $this->user->id,
        'year' => now()->year,
        'month' => now()->month,
        'expected_amount' => 4000,
    ]);

    Payment::factory()->create([
        'contribution_id' => $contribution->id,
        'amount' => 2000,
    ]);

    $tool = new GetMemberOverview($this->user);
    $result = json_decode($tool->handle(new Request), true);

    $member = collect($result['members'])->firstWhere('name', $this->user->name);

    expect($member['paid_this_month'])->toBe(2000)
        ->and($member['outstanding_this_month'])->toBe(2000);
});

it('does not include members from other families', function () {
    $otherFamily = Family::factory()->create();
    User::factory()->count(3)->create(['family_id' => $otherFamily->id]);

    $tool = new GetMemberOverview($this->user);
    $result = json_decode($tool->handle(new Request), true);

    expect($result['total_members'])->toBe(1);
});

it('returns members in alphabetical order', function () {
    User::factory()->create(['family_id' => $this->family->id, 'name' => 'Zara']);
    User::factory()->create(['family_id' => $this->family->id, 'name' => 'Amina']);

    $tool = new GetMemberOverview($this->user);
    $result = json_decode($tool->handle(new Request), true);

    $names = array_column($result['members'], 'name');

    expect($names)->toBe(array_values(collect($names)->sort()->all()));
});
