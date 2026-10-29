<?php

declare(strict_types=1);

use App\Jobs\PurgeArchivedFamily;
use App\Models\Contribution;
use App\Models\Family;
use App\Models\User;
use App\Notifications\ContributionReminderNotification;
use FamilyFunds\D1Staging\D1StagingRuntime;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function (): void {
    if (! config('d1-staging.live_proof')) {
        $this->markTestSkipped('Opt-in scheduling proof restricted to the synthetic D1 scale lab.');
    }
    require_once __DIR__.'/../../deployment/d1-proof/autoload.php';
    if (config('d1-staging.database') !== 'familyfunds-d1-scale-lab') {
        throw new RuntimeException('Scheduling checks require the synthetic scale lab.');
    }
    Notification::fake();
    Storage::fake('local');
});

it('generates a monthly obligation once across repeated native D1 runs', function (): void {
    $runtime = app(D1StagingRuntime::class);
    $familyId = $runtime->run(function (): int {
        $family = Family::factory()->create(['name' => 'D1 schedule generation proof']);
        User::factory()->employed()->withoutTwoFactor()->create([
            'family_id' => $family->id, 'email' => 'd1-schedule-'.Str::uuid().'@example.invalid',
        ]);

        return $family->id;
    });
    for ($attempt = 0; $attempt < 2; $attempt++) {
        $exit = $runtime->run(fn (): int => Artisan::call('contributions:generate', [
            '--family' => $familyId, '--year' => 2024, '--month' => 2, '--no-interaction' => true,
        ]), $familyId);
        expect($exit)->toBe(0);
    }
    expect(Contribution::where('family_id', $familyId)->count())->toBe(1)
        ->and(Contribution::where('family_id', $familyId)->value('expected_amount'))->toBe(4000);
});

it('persists separate reminder claims and suppresses repeated native D1 dispatches', function (): void {
    $runtime = app(D1StagingRuntime::class);
    [$familyId, $memberId, $contributionId] = $runtime->run(function (): array {
        $family = Family::factory()->create(['name' => 'D1 schedule reminder proof']);
        $member = User::factory()->employed()->withoutTwoFactor()->create([
            'family_id' => $family->id, 'email' => 'd1-schedule-'.Str::uuid().'@example.invalid',
        ]);
        $contribution = Contribution::factory()->forUser($member)->currentMonth()->create();

        return [$family->id, $member->id, $contribution->id];
    });
    foreach ([25, 25, 28, 28] as $day) {
        expect($runtime->run(fn (): int => Artisan::call('contributions:remind', ['--day' => $day, '--no-interaction' => true]), $familyId))->toBe(0);
    }
    $contribution = Contribution::findOrFail($contributionId);
    Notification::assertSentToTimes(User::findOrFail($memberId), ContributionReminderNotification::class, 2);
    expect($contribution->reminder_sent_at)->not->toBeNull()
        ->and($contribution->follow_up_sent_at)->not->toBeNull();
});

it('purges only an expired synthetic family and preserves a legal hold on native D1', function (): void {
    $runtime = app(D1StagingRuntime::class);
    [$expiredId, $heldId] = $runtime->run(function (): array {
        $expired = Family::factory()->archived()->create([
            'name' => 'D1 schedule purge proof', 'purge_after' => now()->subDay(),
        ]);
        User::factory()->withoutTwoFactor()->create([
            'family_id' => $expired->id, 'email' => 'd1-schedule-'.Str::uuid().'@example.invalid',
        ]);
        $held = Family::factory()->archived()->create([
            'name' => 'D1 schedule legal hold proof', 'purge_after' => now()->subDay(), 'legal_hold_at' => now(),
        ]);

        return [$expired->id, $held->id];
    });
    foreach ([$expiredId, $expiredId, $heldId] as $familyId) {
        $runtime->run(fn () => (new PurgeArchivedFamily($familyId))->handle(), $familyId);
    }
    expect(Family::find($expiredId))->toBeNull()
        ->and(Family::find($heldId))->not->toBeNull()
        ->and(User::where('family_id', $expiredId)->count())->toBe(0);
});
