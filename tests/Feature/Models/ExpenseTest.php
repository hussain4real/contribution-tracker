<?php

use App\Models\Expense;
use App\Models\Family;
use App\Models\User;
use Carbon\Carbon;

it('casts and formats expense values and exposes relationships', function () {
    $family = Family::factory()->create();
    $recorder = User::factory()->admin()->create(['family_id' => $family->id]);
    $expense = Expense::factory()->recordedBy($recorder)->create([
        'amount' => '12345',
        'spent_at' => '2026-05-11 09:00:00',
    ]);

    expect($expense->amount)->toBe(12345)
        ->and($expense->spent_at)->toBeInstanceOf(Carbon::class)
        ->and($expense->formatted_amount)->toBe("\u{20A6}12,345.00")
        ->and($expense->family->is($family))->toBeTrue()
        ->and($expense->recorder->is($recorder))->toBeTrue();
});

it('filters and orders expenses with local scopes', function () {
    $older = Expense::factory()->spentOn('2026-05-01 09:00:00')->create();
    $insideRange = Expense::factory()->spentOn('2026-05-10 09:00:00')->create();
    $newer = Expense::factory()->spentOn('2026-05-11 09:00:00')->create();

    expect(Expense::spentBetween('2026-05-09 00:00:00', '2026-05-11 23:59:59')->pluck('id')->all())
        ->toEqualCanonicalizing([$insideRange->id, $newer->id])
        ->and(Expense::latestFirst()->pluck('id')->all())->toBe([
            $newer->id,
            $insideRange->id,
            $older->id,
        ]);
});
