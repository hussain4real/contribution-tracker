<?php

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

    $result = json_decode($tool->handle(new Request([
        'year' => 2026,
        'month' => 5,
    ])), true);

    expect($result['status'])->toBe('confirmation_required')
        ->and($result['message'])->toContain('May 2026');
});

test('admin can execute contribution generation', function () {
    // Create a paying member so contributions can be generated
    User::factory()->member()->employed()->create(['family_id' => $this->family->id]);

    $tool = new GenerateContributions($this->admin);

    $result = json_decode($tool->handle(new Request([
        'year' => 2026,
        'month' => 5,
        'confirmed' => true,
    ])), true);

    expect($result['status'])->toBe('success')
        ->and($result['message'])->toContain('May 2026');
});

test('financial secretary cannot generate contributions', function () {
    $tool = new GenerateContributions($this->financialSecretary);

    $result = json_decode($tool->handle(new Request([
        'year' => 2026,
        'month' => 5,
        'confirmed' => true,
    ])), true);

    expect($result['error'])->toContain('Only family admins');
});

test('member cannot generate contributions', function () {
    $tool = new GenerateContributions($this->member);

    $result = json_decode($tool->handle(new Request([
        'year' => 2026,
        'month' => 5,
    ])), true);

    expect($result['error'])->toContain('Only family admins');
});

test('contribution generation defaults to current month', function () {
    $tool = new GenerateContributions($this->admin);

    $result = json_decode($tool->handle(new Request([])), true);

    $expectedMonth = now()->format('F Y');

    expect($result['status'])->toBe('confirmation_required')
        ->and($result['message'])->toContain($expectedMonth);
});
