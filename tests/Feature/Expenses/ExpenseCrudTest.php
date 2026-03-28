<?php

use App\Models\Expense;
use App\Models\User;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    $this->financialSecretary = User::factory()->financialSecretary()->create();
    $this->member = User::factory()->member()->employed()->create();
});

// =========================================================================
// Index
// =========================================================================

it('allows any authenticated user to view the expenses list', function () {
    $this->actingAs($this->member)
        ->get(route('expenses.index'))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page->component('Expenses/Index'));
});

it('returns paginated expenses with correct data', function () {
    Expense::factory()->count(3)->recordedBy($this->admin)->create();

    $this->actingAs($this->admin)
        ->get(route('expenses.index'))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->component('Expenses/Index')
            ->has('expenses.data', 3)
        );
});

it('returns expenses ordered by latest first', function () {
    Expense::factory()->spentOn('2026-01-01')->recordedBy($this->admin)->create(['description' => 'Older']);
    Expense::factory()->spentOn('2026-03-15')->recordedBy($this->admin)->create(['description' => 'Newer']);

    $this->actingAs($this->admin)
        ->get(route('expenses.index'))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->where('expenses.data.0.description', 'Newer')
            ->where('expenses.data.1.description', 'Older')
        );
});

it('shows can_create as true for admin', function () {
    $this->actingAs($this->admin)
        ->get(route('expenses.index'))
        ->assertInertia(fn ($page) => $page->where('can_create', true));
});

it('shows can_create as false for regular member', function () {
    $this->actingAs($this->member)
        ->get(route('expenses.index'))
        ->assertInertia(fn ($page) => $page->where('can_create', false));
});

it('requires authentication to view expenses', function () {
    $this->get(route('expenses.index'))
        ->assertRedirect();
});

// =========================================================================
// Create
// =========================================================================

it('allows super admin to view create expense form', function () {
    $this->actingAs($this->admin)
        ->get(route('expenses.create'))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page->component('Expenses/Create'));
});

it('allows financial secretary to view create expense form', function () {
    $this->actingAs($this->financialSecretary)
        ->get(route('expenses.create'))
        ->assertSuccessful();
});

it('denies regular member access to create expense form', function () {
    $this->actingAs($this->member)
        ->get(route('expenses.create'))
        ->assertForbidden();
});

// =========================================================================
// Store
// =========================================================================

it('allows super admin to store an expense', function () {
    $this->actingAs($this->admin)
        ->post(route('expenses.store'), [
            'amount' => 5000,
            'description' => 'Transport to meeting',
            'spent_at' => '2026-03-15',
        ])
        ->assertRedirect(route('expenses.index'));

    $expense = Expense::first();
    expect($expense)->not->toBeNull();
    expect($expense->amount)->toBe(5000);
    expect($expense->description)->toBe('Transport to meeting');
    expect($expense->spent_at->toDateString())->toBe('2026-03-15');
    expect($expense->recorded_by)->toBe($this->admin->id);
});

it('allows financial secretary to store an expense', function () {
    $this->actingAs($this->financialSecretary)
        ->post(route('expenses.store'), [
            'amount' => 3000,
            'description' => 'Phone calls',
            'spent_at' => '2026-03-14',
        ])
        ->assertRedirect(route('expenses.index'));

    expect(Expense::count())->toBe(1);
    expect(Expense::first()->recorded_by)->toBe($this->financialSecretary->id);
});

it('denies regular member from storing an expense', function () {
    $this->actingAs($this->member)
        ->post(route('expenses.store'), [
            'amount' => 5000,
            'description' => 'Something',
            'spent_at' => '2026-03-15',
        ])
        ->assertForbidden();

    expect(Expense::count())->toBe(0);
});

it('validates required fields when storing', function (string $field) {
    $data = [
        'amount' => 5000,
        'description' => 'Transport',
        'spent_at' => '2026-03-15',
    ];

    unset($data[$field]);

    $this->actingAs($this->admin)
        ->post(route('expenses.store'), $data)
        ->assertSessionHasErrors($field);
})->with(['amount', 'description', 'spent_at']);

it('validates amount must be a positive integer', function () {
    $this->actingAs($this->admin)
        ->post(route('expenses.store'), [
            'amount' => 0,
            'description' => 'Test',
            'spent_at' => '2026-03-15',
        ])
        ->assertSessionHasErrors('amount');
});

it('validates description max length', function () {
    $this->actingAs($this->admin)
        ->post(route('expenses.store'), [
            'amount' => 5000,
            'description' => str_repeat('a', 1001),
            'spent_at' => '2026-03-15',
        ])
        ->assertSessionHasErrors('description');
});

// =========================================================================
// Destroy
// =========================================================================

it('allows super admin to delete an expense', function () {
    $expense = Expense::factory()->recordedBy($this->admin)->create();

    $this->actingAs($this->admin)
        ->delete(route('expenses.destroy', $expense))
        ->assertRedirect(route('expenses.index'));

    expect(Expense::count())->toBe(0);
});

it('denies financial secretary from deleting an expense', function () {
    $expense = Expense::factory()->recordedBy($this->financialSecretary)->create();

    $this->actingAs($this->financialSecretary)
        ->delete(route('expenses.destroy', $expense))
        ->assertForbidden();

    expect(Expense::count())->toBe(1);
});

it('denies regular member from deleting an expense', function () {
    $expense = Expense::factory()->recordedBy($this->admin)->create();

    $this->actingAs($this->member)
        ->delete(route('expenses.destroy', $expense))
        ->assertForbidden();

    expect(Expense::count())->toBe(1);
});
