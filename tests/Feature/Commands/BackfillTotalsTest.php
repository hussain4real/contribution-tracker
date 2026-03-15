<?php

use App\Models\Contribution;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->admin = User::factory()->superAdmin()->create();
});

it('distributes total equally among active paying members and fills backward', function () {
    $employed = User::factory()->member()->employed()->create();
    $student = User::factory()->member()->student()->create();

    $this->artisan('app:backfill-totals', ['--total' => 20000])
        ->assertSuccessful();

    $employedContributions = Contribution::query()->forUser($employed->id)->get();
    $studentContributions = Contribution::query()->forUser($student->id)->get();

    // Each gets ₦10,000
    // Employed: ₦4,000/month → 2 full months + ₦2,000 partial = 3 months
    expect($employedContributions)->toHaveCount(3);
    expect($employedContributions->sum(fn ($c) => $c->total_paid))->toBe(10000);

    // Student: ₦1,000/month → 10 full months
    expect($studentContributions)->toHaveCount(10);
    expect($studentContributions->sum(fn ($c) => $c->total_paid))->toBe(10000);
});

it('fills contributions backward starting from the previous month', function () {
    User::factory()->member()->employed()->create();

    $previousMonth = now()->subMonth();

    $this->artisan('app:backfill-totals', ['--total' => 4000])
        ->assertSuccessful();

    $contribution = Contribution::query()->first();

    expect($contribution->year)->toBe($previousMonth->year);
    expect($contribution->month)->toBe($previousMonth->month);
});

it('skips months that already have contributions', function () {
    $member = User::factory()->member()->employed()->create();

    $previousMonth = now()->subMonth();
    Contribution::create([
        'user_id' => $member->id,
        'year' => $previousMonth->year,
        'month' => $previousMonth->month,
        'expected_amount' => 4000,
    ]);

    $this->artisan('app:backfill-totals', ['--total' => 4000])
        ->assertSuccessful();

    $twoMonthsAgo = now()->subMonths(2);
    $newContribution = Contribution::query()
        ->forUser($member->id)
        ->forMonth($twoMonthsAgo->year, $twoMonthsAgo->month)
        ->first();

    expect($newContribution)->not->toBeNull();
    expect($newContribution->total_paid)->toBe(4000);
});

it('creates payments with correct attributes', function () {
    $member = User::factory()->member()->employed()->create();

    $this->artisan('app:backfill-totals', ['--total' => 4000])
        ->assertSuccessful();

    $payment = Payment::query()->first();
    $previousMonth = now()->subMonth();

    expect($payment->amount)->toBe(4000);
    expect($payment->recorded_by)->toBe($this->admin->id);
    expect($payment->notes)->toBe('Historical backfill');
    expect($payment->paid_at->month)->toBe($previousMonth->month);
    expect($payment->paid_at->year)->toBe($previousMonth->year);
});

it('handles partial last month when share is not evenly divisible by monthly amount', function () {
    $member = User::factory()->member()->employed()->create();

    // ₦6,000 for Employed (₦4,000/month) = 1 full month + ₦2,000 partial
    $this->artisan('app:backfill-totals', ['--total' => 6000])
        ->assertSuccessful();

    $contributions = Contribution::query()->forUser($member->id)->get()->sortByDesc(fn ($c) => $c->year * 100 + $c->month);

    expect($contributions)->toHaveCount(2);

    $payments = Payment::query()->get();
    $amounts = $payments->pluck('amount')->sort()->values()->all();

    expect($amounts)->toBe([2000, 4000]);
});

it('fails with zero total', function () {
    User::factory()->member()->employed()->create();

    $this->artisan('app:backfill-totals', ['--total' => 0])
        ->assertFailed();

    expect(Contribution::query()->count())->toBe(0);
});

it('fails without the total option', function () {
    User::factory()->member()->employed()->create();

    $this->artisan('app:backfill-totals')
        ->assertFailed();
});

it('fails when no active paying members exist', function () {
    $this->artisan('app:backfill-totals', ['--total' => 10000])
        ->assertFailed();
});

it('fails when no super admin exists', function () {
    $this->admin->delete();
    User::factory()->member()->employed()->create();

    $this->artisan('app:backfill-totals', ['--total' => 10000])
        ->assertFailed();
});

it('handles non-evenly-divisible total among members', function () {
    User::factory()->member()->employed()->create();
    User::factory()->member()->employed()->create();
    User::factory()->member()->employed()->create();

    // ₦10,000 / 3 members = ₦3,333 each, ₦1 remainder
    $this->artisan('app:backfill-totals', ['--total' => 10000])
        ->assertSuccessful();

    $totalAllocated = Payment::query()->sum('amount');

    // 3 members × ₦3,333 = ₦9,999 (₦1 lost to integer division remainder)
    expect($totalAllocated)->toBe(9999);
});

it('does not affect archived members', function () {
    User::factory()->member()->employed()->create();
    $archived = User::factory()->member()->employed()->archived()->create();

    $this->artisan('app:backfill-totals', ['--total' => 8000])
        ->assertSuccessful();

    $archivedContributions = Contribution::query()->forUser($archived->id)->count();

    expect($archivedContributions)->toBe(0);
});

it('outputs a summary table', function () {
    $member = User::factory()->member()->employed()->create(['name' => 'Test Member']);

    $this->artisan('app:backfill-totals', ['--total' => 4000])
        ->expectsOutputToContain('Test Member')
        ->expectsOutputToContain('Backfill complete')
        ->assertSuccessful();
});
