<?php

use App\Models\Contribution;
use App\Models\Expense;
use App\Models\FundAdjustment;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->admin = User::factory()->superAdmin()->create();
    $this->member = User::factory()->member()->employed()->create();
});

it('returns fund_balance as a prop for admin dashboard', function () {
    $this->actingAs($this->admin)
        ->get(route('dashboard'))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->component('Dashboard/Index')
            ->has('fund_balance')
        );
});

it('returns fund_balance as a prop for member dashboard', function () {
    $this->actingAs($this->member)
        ->get(route('dashboard'))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->component('Dashboard/Index')
            ->has('fund_balance')
        );
});

it('calculates fund_balance as zero when no data exists', function () {
    $this->actingAs($this->admin)
        ->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page
            ->where('fund_balance', 0)
        );
});

it('calculates fund_balance = payments + adjustments - expenses', function () {
    $contribution = Contribution::factory()
        ->for($this->member)
        ->create(['expected_amount' => 4000]);

    Payment::factory()->create([
        'contribution_id' => $contribution->id,
        'amount' => 4000,
        'recorded_by' => $this->admin->id,
    ]);

    FundAdjustment::factory()->recordedBy($this->admin)->create([
        'amount' => 200000,
    ]);

    Expense::factory()->recordedBy($this->admin)->create([
        'amount' => 5000,
    ]);

    // fund_balance = 4000 + 200000 - 5000 = 199000
    $this->actingAs($this->admin)
        ->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page
            ->where('fund_balance', 199000)
        );
});

it('shows negative fund_balance when expenses exceed income', function () {
    Expense::factory()->recordedBy($this->admin)->create([
        'amount' => 10000,
    ]);

    // fund_balance = 0 + 0 - 10000 = -10000
    $this->actingAs($this->admin)
        ->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page
            ->where('fund_balance', -10000)
        );
});
