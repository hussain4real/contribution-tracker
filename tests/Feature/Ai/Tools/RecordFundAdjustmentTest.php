<?php

use App\Ai\Tools\RecordFundAdjustment;
use App\Models\Family;
use App\Models\User;
use Laravel\Ai\Tools\Request;

beforeEach(function () {
    $this->family = Family::factory()->create();
    $this->admin = User::factory()->admin()->create(['family_id' => $this->family->id]);
    $this->financialSecretary = User::factory()->financialSecretary()->create(['family_id' => $this->family->id]);
    $this->member = User::factory()->member()->create(['family_id' => $this->family->id]);
});

test('admin can preview fund adjustment recording', function () {
    $tool = new RecordFundAdjustment($this->admin);

    $result = json_decode($tool->handle(new Request([
        'amount' => 50000,
        'description' => 'Donation from community',
        'recorded_at' => '2026-04-01',
    ])), true);

    expect($result['status'])->toBe('confirmation_required')
        ->and($result['message'])->toContain('50,000')
        ->and($result['message'])->toContain('Donation from community');

    $this->assertDatabaseCount('fund_adjustments', 0);
});

test('admin can execute fund adjustment recording', function () {
    $tool = new RecordFundAdjustment($this->admin);

    $result = json_decode($tool->handle(new Request([
        'amount' => 50000,
        'description' => 'Donation from community',
        'recorded_at' => '2026-04-01',
        'confirmed' => true,
    ])), true);

    expect($result['status'])->toBe('success')
        ->and($result['adjustment_id'])->toBeInt();

    $this->assertDatabaseHas('fund_adjustments', [
        'family_id' => $this->family->id,
        'amount' => 50000,
        'description' => 'Donation from community',
        'recorded_by' => $this->admin->id,
    ]);
});

test('financial secretary can record fund adjustments', function () {
    $tool = new RecordFundAdjustment($this->financialSecretary);

    $result = json_decode($tool->handle(new Request([
        'amount' => 10000,
        'description' => 'Interest earned',
        'recorded_at' => '2026-04-01',
        'confirmed' => true,
    ])), true);

    expect($result['status'])->toBe('success');

    $this->assertDatabaseHas('fund_adjustments', [
        'recorded_by' => $this->financialSecretary->id,
    ]);
});

test('member cannot record fund adjustments', function () {
    $tool = new RecordFundAdjustment($this->member);

    $result = json_decode($tool->handle(new Request([
        'amount' => 50000,
        'description' => 'Donation',
        'confirmed' => true,
    ])), true);

    expect($result['error'])->toContain('do not have permission');

    $this->assertDatabaseCount('fund_adjustments', 0);
});

test('fund adjustment validates required fields', function () {
    $tool = new RecordFundAdjustment($this->admin);

    $noAmount = json_decode($tool->handle(new Request([
        'description' => 'Test',
    ])), true);

    $noDescription = json_decode($tool->handle(new Request([
        'amount' => 5000,
    ])), true);

    expect($noAmount['error'])->toContain('Amount is required')
        ->and($noDescription['error'])->toContain('description is required');
});
