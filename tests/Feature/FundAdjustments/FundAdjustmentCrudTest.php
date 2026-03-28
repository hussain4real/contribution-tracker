<?php

use App\Models\FundAdjustment;
use App\Models\User;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    $this->financialSecretary = User::factory()->financialSecretary()->create();
    $this->member = User::factory()->member()->employed()->create();
});

// =========================================================================
// Index
// =========================================================================

it('allows any authenticated user to view fund adjustments', function () {
    $this->actingAs($this->member)
        ->get(route('fund-adjustments.index'))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page->component('FundAdjustments/Index'));
});

it('returns paginated adjustments with correct data', function () {
    FundAdjustment::factory()->count(3)->recordedBy($this->admin)->create();

    $this->actingAs($this->admin)
        ->get(route('fund-adjustments.index'))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->component('FundAdjustments/Index')
            ->has('adjustments.data', 3)
        );
});

it('shows can_create as true for super admin', function () {
    $this->actingAs($this->admin)
        ->get(route('fund-adjustments.index'))
        ->assertInertia(fn ($page) => $page->where('can_create', true));
});

it('shows can_create as false for financial secretary', function () {
    $this->actingAs($this->financialSecretary)
        ->get(route('fund-adjustments.index'))
        ->assertInertia(fn ($page) => $page->where('can_create', false));
});

it('shows can_create as false for regular member', function () {
    $this->actingAs($this->member)
        ->get(route('fund-adjustments.index'))
        ->assertInertia(fn ($page) => $page->where('can_create', false));
});

// =========================================================================
// Store
// =========================================================================

it('allows super admin to store a fund adjustment', function () {
    $this->actingAs($this->admin)
        ->post(route('fund-adjustments.store'), [
            'amount' => 200000,
            'description' => 'Opening balance from 2+ years of contributions',
            'recorded_at' => '2026-03-15',
        ])
        ->assertRedirect(route('fund-adjustments.index'));

    $adjustment = FundAdjustment::first();
    expect($adjustment)->not->toBeNull();
    expect($adjustment->amount)->toBe(200000);
    expect($adjustment->description)->toBe('Opening balance from 2+ years of contributions');
    expect($adjustment->recorded_at->toDateString())->toBe('2026-03-15');
    expect($adjustment->recorded_by)->toBe($this->admin->id);
});

it('denies financial secretary from storing a fund adjustment', function () {
    $this->actingAs($this->financialSecretary)
        ->post(route('fund-adjustments.store'), [
            'amount' => 200000,
            'description' => 'Opening balance',
            'recorded_at' => '2026-03-15',
        ])
        ->assertForbidden();

    expect(FundAdjustment::count())->toBe(0);
});

it('denies regular member from storing a fund adjustment', function () {
    $this->actingAs($this->member)
        ->post(route('fund-adjustments.store'), [
            'amount' => 200000,
            'description' => 'Opening balance',
            'recorded_at' => '2026-03-15',
        ])
        ->assertForbidden();

    expect(FundAdjustment::count())->toBe(0);
});

it('validates required fields when storing', function (string $field) {
    $data = [
        'amount' => 200000,
        'description' => 'Opening balance',
        'recorded_at' => '2026-03-15',
    ];

    unset($data[$field]);

    $this->actingAs($this->admin)
        ->post(route('fund-adjustments.store'), $data)
        ->assertSessionHasErrors($field);
})->with(['amount', 'description', 'recorded_at']);

it('validates amount must be a positive integer', function () {
    $this->actingAs($this->admin)
        ->post(route('fund-adjustments.store'), [
            'amount' => 0,
            'description' => 'Test',
            'recorded_at' => '2026-03-15',
        ])
        ->assertSessionHasErrors('amount');
});

// =========================================================================
// Destroy
// =========================================================================

it('allows super admin to delete a fund adjustment', function () {
    $adjustment = FundAdjustment::factory()->recordedBy($this->admin)->create();

    $this->actingAs($this->admin)
        ->delete(route('fund-adjustments.destroy', $adjustment))
        ->assertRedirect(route('fund-adjustments.index'));

    expect(FundAdjustment::count())->toBe(0);
});

it('denies financial secretary from deleting a fund adjustment', function () {
    $adjustment = FundAdjustment::factory()->recordedBy($this->admin)->create();

    $this->actingAs($this->financialSecretary)
        ->delete(route('fund-adjustments.destroy', $adjustment))
        ->assertForbidden();

    expect(FundAdjustment::count())->toBe(1);
});

it('denies regular member from deleting a fund adjustment', function () {
    $adjustment = FundAdjustment::factory()->recordedBy($this->admin)->create();

    $this->actingAs($this->member)
        ->delete(route('fund-adjustments.destroy', $adjustment))
        ->assertForbidden();

    expect(FundAdjustment::count())->toBe(1);
});
