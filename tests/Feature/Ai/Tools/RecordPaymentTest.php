<?php

use App\Ai\Tools\RecordPayment;
use App\Models\Contribution;
use App\Models\Family;
use App\Models\User;
use Laravel\Ai\Tools\Request;

beforeEach(function () {
    $this->family = Family::factory()->create();
    $this->admin = User::factory()->admin()->create(['family_id' => $this->family->id]);
    $this->payingMember = User::factory()->member()->employed()->create([
        'family_id' => $this->family->id,
        'name' => 'Aminu Hussain',
    ]);
    $this->member = User::factory()->member()->create(['family_id' => $this->family->id]);
});

test('admin can preview payment recording', function () {
    $tool = new RecordPayment($this->admin);

    $result = json_decode($tool->handle(new Request([
        'member_name' => 'Aminu',
        'amount' => 4000,
        'paid_at' => '2026-04-01',
    ])), true);

    expect($result['status'])->toBe('confirmation_required')
        ->and($result['message'])->toContain('4,000')
        ->and($result['message'])->toContain('Aminu Hussain');

    $this->assertDatabaseCount('payments', 0);
});

test('admin can execute payment recording with confirmation', function () {
    Contribution::factory()->forUser($this->payingMember)->currentMonth()->create();

    $tool = new RecordPayment($this->admin);

    $result = json_decode($tool->handle(new Request([
        'member_name' => 'Aminu',
        'amount' => 4000,
        'paid_at' => '2026-04-01',
        'confirmed' => true,
    ])), true);

    expect($result['status'])->toBe('success')
        ->and($result['payments_created'])->toBeGreaterThanOrEqual(1)
        ->and($result['total_allocated'])->toBe(4000);
});

test('member cannot record payments', function () {
    $tool = new RecordPayment($this->member);

    $result = json_decode($tool->handle(new Request([
        'member_name' => 'Aminu',
        'amount' => 4000,
        'confirmed' => true,
    ])), true);

    expect($result['error'])->toContain('do not have permission');
});

test('payment recording returns error for unknown member', function () {
    $tool = new RecordPayment($this->admin);

    $result = json_decode($tool->handle(new Request([
        'member_name' => 'Nonexistent Person',
        'amount' => 4000,
    ])), true);

    expect($result['error'])->toContain('No active family member found');
});

test('payment recording handles ambiguous member names', function () {
    User::factory()->member()->create([
        'family_id' => $this->family->id,
        'name' => 'Aminu Ibrahim',
    ]);

    $tool = new RecordPayment($this->admin);

    $result = json_decode($tool->handle(new Request([
        'member_name' => 'Aminu',
        'amount' => 4000,
    ])), true);

    expect($result['error'])->toContain('Multiple members')
        ->and($result['matching_members'])->toContain('Aminu Hussain')
        ->and($result['matching_members'])->toContain('Aminu Ibrahim');
});

test('payment recording validates member has contribution category', function () {
    $noCategoryMember = User::factory()->member()->nonPaying()->create([
        'family_id' => $this->family->id,
        'name' => 'Unique Nopay Test',
    ]);

    $tool = new RecordPayment($this->admin);

    $result = json_decode($tool->handle(new Request([
        'member_name' => 'Unique Nopay',
        'amount' => 4000,
    ])), true);

    expect($result['error'])->toContain('does not have a contribution category');
});

test('payment recording validates advance payment limit', function () {
    $tool = new RecordPayment($this->admin);

    $result = json_decode($tool->handle(new Request([
        'member_name' => 'Aminu Hussain',
        'amount' => 4000,
        'target_year' => now()->addMonths(7)->year,
        'target_month' => now()->addMonths(7)->month,
    ])), true);

    expect($result['error'])->toContain('limited to 6 months');
});
