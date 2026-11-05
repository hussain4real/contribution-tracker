<?php

declare(strict_types=1);

use App\Ai\Tools\GenerateContributions;
use App\Models\Family;
use App\Models\User;
use Laravel\Ai\Tools\Request;

beforeEach(function () {
    $this->family = Family::factory()->create();
    $this->admin = User::factory()->admin()->create(['family_id' => $this->family->id]);
    $this->financialSecretary = User::factory()->financialSecretary()->create(['family_id' => $this->family->id]);
    $this->member = User::factory()->member()->create(['family_id' => $this->family->id]);
});

test('admin can preview contribution generation', function () {
    $tool = new GenerateContributions($this->admin);

    $result = decodeToolResult($tool->handle(new Request([
        'year' => 2026,
        'month' => 5,
    ])));

    expect($result['status'])->toBe('confirmation_required')
        ->and($result['message'])->toContain('May 2026');
    $this->assertDatabaseCount('contributions', 0);
});

test('chat confirmation only prepares generation without creating contributions', function () {
    // Create a paying member so contributions can be generated
    User::factory()->member()->employed()->create(['family_id' => $this->family->id]);

    $tool = new GenerateContributions($this->admin);

    $result = decodeToolResult($tool->handle(new Request([
        'year' => 2026,
        'month' => 5,
        'confirmed' => true,
    ])));

    expect($result['status'])->toBe('confirmation_required')
        ->and($result['message'])->toContain('May 2026');
    $this->assertDatabaseCount('contributions', 0);
});

test('financial secretary cannot generate contributions', function () {
    $tool = new GenerateContributions($this->financialSecretary);

    $result = decodeToolResult($tool->handle(new Request([
        'year' => 2026,
        'month' => 5,
        'confirmed' => true,
    ])));

    expect($result['error'])->toContain('Only family admins');
});

test('member cannot generate contributions', function () {
    $tool = new GenerateContributions($this->member);

    $result = decodeToolResult($tool->handle(new Request([
        'year' => 2026,
        'month' => 5,
    ])));

    expect($result['error'])->toContain('Only family admins');
});

test('contribution generation defaults to current month', function () {
    $tool = new GenerateContributions($this->admin);

    $result = decodeToolResult($tool->handle(new Request([])));

    $expectedMonth = now()->format('F Y');

    expect($result['status'])->toBe('confirmation_required')
        ->and($result['message'])->toContain($expectedMonth);
});
