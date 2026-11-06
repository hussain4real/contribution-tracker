<?php

declare(strict_types=1);

use App\Ai\Tools\RecordPayment;
use App\Models\Contribution;
use App\Models\Family;
use App\Models\User;
use Illuminate\Support\Carbon;
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

    $result = decodeToolResult($tool->handle(new Request([
        'member_name' => 'Aminu',
        'amount' => 4000,
        'paid_at' => '2026-04-01',
    ])));

    expect($result['status'])->toBe('confirmation_required')
        ->and($result['message'])->toContain('4,000')
        ->and($result['message'])->toContain('Aminu Hussain');

    $this->assertDatabaseCount('payments', 0);
});

test('payment recording requires a member name', function () {
    $tool = new RecordPayment($this->admin);

    $result = decodeToolResult($tool->handle(new Request([
        'amount' => 4000,
    ])));

    expect($result['error'])->toBe('Please provide the member name.');
});

test('payment recording requires a positive amount', function () {
    $tool = new RecordPayment($this->admin);

    $result = decodeToolResult($tool->handle(new Request([
        'member_name' => 'Aminu Hussain',
        'amount' => 0,
    ])));

    expect($result['error'])->toContain('Amount is required');
});

test('payment recording preview includes a valid target period', function () {
    $target = now()->startOfMonth()->addMonths(2);
    $tool = new RecordPayment($this->admin);

    $result = decodeToolResult($tool->handle(new Request([
        'member_name' => 'Aminu Hussain',
        'amount' => 4000,
        'target_year' => $target->year,
        'target_month' => $target->month,
    ])));

    expect($result['status'])->toBe('confirmation_required')
        ->and($result['message'])->toContain($target->format('F Y'))
        ->and(resultArray($result, 'details')['target_year'])->toBe($target->year)
        ->and(resultArray($result, 'details')['target_month'])->toBe($target->month);
});

test('payment preview displays the selected month at month end', function () {
    $this->travelTo(Carbon::parse('2026-08-31 12:00:00'));
    $tool = new RecordPayment($this->admin);

    $result = decodeToolResult($tool->handle(new Request([
        'member_name' => 'Aminu',
        'amount' => 4000,
        'target_year' => 2027,
        'target_month' => 2,
    ])));

    expect($result['status'])->toBe('confirmation_required')
        ->and($result['message'])->toContain('February 2027');
});

test('chat confirmation returns a payment draft without posting', function () {
    Contribution::factory()->forUser($this->payingMember)->currentMonth()->create();

    $tool = new RecordPayment($this->admin);

    $result = decodeToolResult($tool->handle(new Request([
        'member_name' => 'Aminu',
        'amount' => 4000,
        'paid_at' => '2026-04-01',
        'confirmed' => true,
    ])));

    expect($result['status'])->toBe('confirmation_required')
        ->and($result['review_url'])->toBeString();
    $this->assertDatabaseCount('payments', 0);
});

test('member cannot record payments', function () {
    $tool = new RecordPayment($this->member);

    $result = decodeToolResult($tool->handle(new Request([
        'member_name' => 'Aminu',
        'amount' => 4000,
        'confirmed' => true,
    ])));

    expect($result['error'])->toContain('do not have permission');
});

test('payment recording returns error for unknown member', function () {
    $tool = new RecordPayment($this->admin);

    $result = decodeToolResult($tool->handle(new Request([
        'member_name' => 'Nonexistent Person',
        'amount' => 4000,
    ])));

    expect($result['error'])->toContain('No active family member found');
});

test('payment recording handles ambiguous member names', function () {
    User::factory()->member()->create([
        'family_id' => $this->family->id,
        'name' => 'Aminu Ibrahim',
    ]);

    $tool = new RecordPayment($this->admin);

    $result = decodeToolResult($tool->handle(new Request([
        'member_name' => 'Aminu',
        'amount' => 4000,
    ])));

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

    $result = decodeToolResult($tool->handle(new Request([
        'member_name' => 'Unique Nopay',
        'amount' => 4000,
    ])));

    expect($result['error'])->toContain('does not have a contribution category');
});

test('payment recording validates advance payment limit', function () {
    $this->travelTo(now()->setDate(2026, 10, 31));
    $tool = new RecordPayment($this->admin);

    $result = decodeToolResult($tool->handle(new Request([
        'member_name' => 'Aminu Hussain',
        'amount' => 4000,
        'target_year' => now()->startOfMonth()->addMonths(7)->year,
        'target_month' => now()->startOfMonth()->addMonths(7)->month,
    ])));

    expect($result['error'])->toContain('limited to 6 months');
});

test('payment recording previews the selected month on the last day of a longer month', function () {
    $this->travelTo(now()->setDate(2026, 10, 31));
    $result = decodeToolResult((new RecordPayment($this->admin))->handle(new Request([
        'member_name' => 'Aminu Hussain',
        'amount' => 4000,
        'target_year' => 2026,
        'target_month' => 11,
    ])));

    expect($result['status'])->toBe('confirmation_required')
        ->and($result['message'])->toContain('November 2026');
});
