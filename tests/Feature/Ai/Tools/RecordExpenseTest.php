<?php

use App\Ai\Tools\RecordExpense;
use App\Models\Family;
use App\Models\User;
use Laravel\Ai\Tools\Request;

beforeEach(function () {
    $this->family = Family::factory()->create();
    $this->admin = User::factory()->admin()->create(['family_id' => $this->family->id]);
    $this->financialSecretary = User::factory()->financialSecretary()->create(['family_id' => $this->family->id]);
    $this->member = User::factory()->member()->create(['family_id' => $this->family->id]);
});

test('admin can preview expense recording', function () {
    $tool = new RecordExpense($this->admin);

    $result = json_decode($tool->handle(new Request([
        'amount' => 5000,
        'description' => 'Generator fuel',
        'spent_at' => '2026-04-01',
    ])), true);

    expect($result['status'])->toBe('confirmation_required')
        ->and($result['message'])->toContain('5,000')
        ->and($result['message'])->toContain('Generator fuel');

    $this->assertDatabaseMissing('expenses', ['description' => 'Generator fuel']);
});

test('admin can execute expense recording with confirmation', function () {
    $tool = new RecordExpense($this->admin);

    $result = json_decode($tool->handle(new Request([
        'amount' => 5000,
        'description' => 'Generator fuel',
        'spent_at' => '2026-04-01',
        'confirmed' => true,
    ])), true);

    expect($result['status'])->toBe('success')
        ->and($result['expense_id'])->toBeInt();

    $this->assertDatabaseHas('expenses', [
        'family_id' => $this->family->id,
        'amount' => 5000,
        'description' => 'Generator fuel',
        'recorded_by' => $this->admin->id,
    ]);
});

test('financial secretary can record expenses', function () {
    $tool = new RecordExpense($this->financialSecretary);

    $result = json_decode($tool->handle(new Request([
        'amount' => 3000,
        'description' => 'Office supplies',
        'spent_at' => '2026-04-01',
        'confirmed' => true,
    ])), true);

    expect($result['status'])->toBe('success');

    $this->assertDatabaseHas('expenses', [
        'family_id' => $this->family->id,
        'amount' => 3000,
        'recorded_by' => $this->financialSecretary->id,
    ]);
});

test('member cannot record expenses', function () {
    $tool = new RecordExpense($this->member);

    $result = json_decode($tool->handle(new Request([
        'amount' => 5000,
        'description' => 'Generator fuel',
        'confirmed' => true,
    ])), true);

    expect($result['error'])->toContain('do not have permission');

    $this->assertDatabaseCount('expenses', 0);
});

test('expense recording validates required amount', function () {
    $tool = new RecordExpense($this->admin);

    $result = json_decode($tool->handle(new Request([
        'description' => 'Generator fuel',
    ])), true);

    expect($result['error'])->toContain('Amount is required');
});

test('expense recording validates required description', function () {
    $tool = new RecordExpense($this->admin);

    $result = json_decode($tool->handle(new Request([
        'amount' => 5000,
    ])), true);

    expect($result['error'])->toContain('description is required');
});
