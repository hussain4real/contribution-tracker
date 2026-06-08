<?php

declare(strict_types=1);

use App\Models\Family;
use App\Models\FundAdjustment;
use App\Models\User;
use Carbon\Carbon;

it('casts and formats fund adjustment values and exposes relationships', function () {
    $family = Family::factory()->create();
    $recorder = User::factory()->admin()->create(['family_id' => $family->id]);
    $adjustment = FundAdjustment::factory()->recordedBy($recorder)->create([
        'amount' => '250000',
        'recorded_at' => '2026-05-11 09:00:00',
    ]);

    expect($adjustment->amount)->toBe(250000)
        ->and($adjustment->recorded_at)->toBeInstanceOf(Carbon::class)
        ->and($adjustment->formatted_amount)->toBe("\u{20A6}250,000.00")
        ->and($adjustment->family()->firstOrFail()->is($family))->toBeTrue()
        ->and($adjustment->recorder()->firstOrFail()->is($recorder))->toBeTrue();
});

it('formats fund adjustments with the family currency', function () {
    $family = Family::factory()->create(['currency' => 'QAR']);
    $recorder = User::factory()->admin()->create(['family_id' => $family->id]);
    $adjustment = FundAdjustment::factory()->recordedBy($recorder)->create([
        'amount' => 450,
    ]);

    expect($adjustment->formatted_amount)->toBe('QAR 450.00');
});

it('orders fund adjustments by latest first', function () {
    $older = FundAdjustment::factory()->create(['recorded_at' => '2026-05-01 09:00:00']);
    $newer = FundAdjustment::factory()->create(['recorded_at' => '2026-05-11 09:00:00']);

    expect(FundAdjustment::latestFirst()->pluck('id')->all())->toBe([
        $newer->id,
        $older->id,
    ]);
});
