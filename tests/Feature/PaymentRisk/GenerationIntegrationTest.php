<?php

declare(strict_types=1);

use App\Models\Contribution;
use App\Models\Family;
use App\Models\PaymentRiskPrediction;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

it('keeps monthly contribution generation successful when no advisory model is active', function () {
    $family = Family::factory()->create();
    User::factory()->member()->create(['family_id' => $family->id]);

    $this->artisan('contributions:generate', ['--family' => $family->id])
        ->expectsOutputToContain('Created 1 contributions')
        ->expectsOutputToContain('Payment-risk scoring skipped')
        ->assertSuccessful();

    expect(Contribution::query()->where('family_id', $family->id)->count())->toBe(1)
        ->and(PaymentRiskPrediction::query()->count())->toBe(0);
});

it('runs the same idempotent advisory scoring action after monthly generation', function () {
    Storage::fake('local');
    installActiveTestPaymentRiskModel();
    $family = Family::factory()->create();
    User::factory()->member()->create(['family_id' => $family->id]);

    $this->artisan('contributions:generate', ['--family' => $family->id])
        ->expectsOutputToContain('Stored 1 advisory payment-risk predictions')
        ->assertSuccessful();
    $this->artisan('contributions:generate', ['--family' => $family->id])
        ->expectsOutputToContain('Stored 0 advisory payment-risk predictions')
        ->assertSuccessful();

    expect(Contribution::query()->where('family_id', $family->id)->count())->toBe(1)
        ->and(PaymentRiskPrediction::query()->where('family_id', $family->id)->count())->toBe(1);
});

it('scores a family by slug and validates command inputs', function () {
    Storage::fake('local');
    installActiveTestPaymentRiskModel();
    $family = Family::factory()->create();
    $member = User::factory()->member()->create(['family_id' => $family->id]);
    $period = now()->toImmutable()->startOfMonth();
    scoreTestContribution($family, $member, $period);

    $this->artisan('payment-risk:score', [
        'family' => $family->slug,
        '--period' => $period->format('Y-m'),
    ])->assertSuccessful();

    $this->artisan('payment-risk:score', [
        'family' => $family->slug,
        '--period' => '2026-13',
    ])->expectsOutputToContain('YYYY-MM')->assertFailed();

    $this->artisan('payment-risk:score', [
        'family' => 'missing-family',
        '--period' => $period->format('Y-m'),
    ])->expectsOutputToContain('not found')->assertFailed();
});

it('rejects blank command arguments and reports scoring failures', function () {
    $family = Family::factory()->create();

    $this->artisan('payment-risk:install', ['artifact' => ' '])
        ->expectsOutputToContain('artifact path is required')
        ->assertFailed();
    $this->artisan('payment-risk:score', [
        'family' => ' ',
        '--period' => now()->format('Y-m'),
    ])->expectsOutputToContain('family ID or slug is required')->assertFailed();
    $this->artisan('payment-risk:score', [
        'family' => $family->slug,
        '--period' => now()->format('Y-m'),
    ])->expectsOutputToContain('No active payment-risk model')->assertFailed();
});

it('scores a family by its numeric identifier', function () {
    Storage::fake('local');
    installActiveTestPaymentRiskModel();
    $family = Family::factory()->create();
    $member = User::factory()->member()->create(['family_id' => $family->id]);
    $period = now()->toImmutable()->startOfMonth();
    scoreTestContribution($family, $member, $period);

    $this->artisan('payment-risk:score', [
        'family' => (string) $family->id,
        '--period' => $period->format('Y-m'),
    ])->assertSuccessful();

    expect(PaymentRiskPrediction::query()->where('family_id', $family->id)->count())->toBe(1);
});

it('installs a validated artifact through the payment-risk command', function () {
    Storage::fake('local');
    $artifact = paymentRiskArtifactFile(validPaymentRiskArtifact());

    $this->artisan('payment-risk:install', [
        'artifact' => $artifact->getPathname(),
        '--activate' => true,
    ])
        ->expectsTable(
            ['Version', 'Checksum', 'Eligible', 'Active'],
            [[
                'test-model-v1',
                hash_file('sha256', $artifact->getPathname()),
                'yes',
                'yes',
            ]],
        )
        ->assertSuccessful();

    $this->artisan('payment-risk:install', [
        'artifact' => $artifact->getPathname().'.missing',
    ])->expectsOutputToContain('does not exist')->assertFailed();
});
