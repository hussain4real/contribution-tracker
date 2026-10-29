<?php

declare(strict_types=1);

use App\Models\Contribution;
use App\Models\Family;
use App\Models\Payment;
use App\Models\PaymentBatch;
use App\Models\User;
use App\Services\PaymentAllocationService;
use FamilyFunds\D1Staging\D1StagingConnection;
use FamilyFunds\D1Staging\D1StagingRuntime;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function (): void {
    if (! config('d1-staging.live_proof')) {
        $this->markTestSkipped('Opt-in live leased D1 proof restricted to the synthetic scale lab.');
    }
    require_once __DIR__.'/../../deployment/d1-proof/autoload.php';
    if (config('d1-staging.database') !== 'familyfunds-d1-scale-lab') {
        throw new RuntimeException('Leased proof must target the synthetic scale lab.');
    }
    D1StagingRuntime::install();
});

it('refreshes a shared read cache after an atomic write on real D1', function (): void {
    D1StagingRuntime::installReadCache();
    $runtime = app(D1StagingRuntime::class);
    [$familyId, $actorId] = $runtime->run(function (): array {
        $family = Family::factory()->create(['name' => 'Native read cache proof']);
        $actor = User::factory()->member()->withoutTwoFactor()->create(['family_id' => $family->id]);
        Contribution::factory()->create(['family_id' => $family->id, 'user_id' => $actor->id, 'expected_amount' => 4000]);

        return [$family->id, $actor->id];
    });
    $directory = storage_path('framework/testing/d1-native-cache-'.Str::uuid());
    config(['d1-staging.read_snapshot_cache' => true, 'd1-staging.read_snapshot_path' => $directory]);
    try {
        expect((int) $runtime->runReadOnly(fn () => Contribution::sum('expected_amount'), $familyId, $actorId))->toBe(4000);
        $runtime->run(fn () => Contribution::where('family_id', $familyId)->update(['expected_amount' => 4100]), $familyId, $actorId);
        expect((int) $runtime->runReadOnly(fn () => Contribution::sum('expected_amount'), $familyId, $actorId))->toBe(4100)
            ->and((int) $runtime->runReadOnly(fn () => Contribution::sum('expected_amount'), $familyId, $actorId))->toBe(4100);
    } finally {
        config(['d1-staging.read_snapshot_cache' => false]);
        File::deleteDirectory($directory);
    }
});

it('posts fifty genuine arrears allocations as one atomic receipt', function (): void {
    $runtime = app(D1StagingRuntime::class);
    $ids = $runtime->run(function (): array {
        $family = Family::factory()->create(['name' => 'Leased D1 synthetic arrears', 'currency' => 'NGN']);
        $actor = User::factory()->financialSecretary()->withoutTwoFactor()->create(['family_id' => $family->id]);
        $member = User::factory()->member()->employed()->withoutTwoFactor()->create(['family_id' => $family->id]);
        for ($month = 0; $month < 50; $month++) {
            Contribution::factory()->create(['family_id' => $family->id, 'user_id' => $member->id, 'year' => 2020 + intdiv($month, 12), 'month' => 1 + $month % 12, 'expected_amount' => 4000]);
        }

        return [$family->id, $actor->id, $member->id];
    });
    [$familyId, $actorId, $memberId] = $ids;
    $key = (string) Str::uuid();
    $batchId = $runtime->run(fn (): int => app(PaymentAllocationService::class)->createBatch(
        member: User::findOrFail($memberId), amount: 200000, paidAt: now(), recordedBy: User::findOrFail($actorId), family: Family::findOrFail($familyId), idempotencyKey: $key,
    )->id, $familyId);
    expect(Payment::where('payment_batch_id', $batchId)->count())->toBe(50)
        ->and((int) Payment::where('payment_batch_id', $batchId)->sum('amount'))->toBe(200000);
    $repeated = $runtime->run(fn (): int => app(PaymentAllocationService::class)->createBatch(
        member: User::findOrFail($memberId), amount: 200000, paidAt: now(), recordedBy: User::findOrFail($actorId), family: Family::findOrFail($familyId), idempotencyKey: $key,
    )->id, $familyId);
    expect($repeated)->toBe($batchId)
        ->and(PaymentBatch::where('family_id', $familyId)->count())->toBe(1);
});

it('preserves rollback when an inner transaction fails', function (): void {
    $runtime = app(D1StagingRuntime::class);
    $familyId = $runtime->run(fn (): int => Family::factory()->create(['name' => 'Leased D1 rollback'])->id);
    expect(fn () => $runtime->run(fn () => DB::transaction(function () use ($familyId): void {
        Family::findOrFail($familyId)->update(['name' => 'Must not commit']);
        throw new InvalidArgumentException('Synthetic rollback');
    }), $familyId))->toThrow(InvalidArgumentException::class, 'Synthetic rollback');
    expect(Family::findOrFail($familyId)->name)->toBe('Leased D1 rollback');
});

it('fences a stale financial owner on the real D1 database', function (): void {
    $runtime = app(D1StagingRuntime::class);
    $familyId = $runtime->run(fn (): int => Family::factory()->create(['name' => 'Leased D1 fenced'])->id);
    $token = (string) Str::uuid();
    $scope = 'family:'.$familyId;
    try {
        expect(fn () => $runtime->run(function () use ($familyId, $scope, $token): void {
            Family::findOrFail($familyId)->update(['name' => 'Must not commit']);
            $sqlToken = config('d1-staging.token');
            $url = config('d1-staging.url');
            assert(is_string($sqlToken) && is_string($url));
            Http::withToken($sqlToken)->post($url.'/query', ['sql' => 'update d1_staging_leases set token=?,expires_at=unixepoch()+120 where scope=?', 'params' => [$token, $scope]])->throw();
        }, $familyId))->toThrow(RuntimeException::class, 'd1_staging_fresh_snapshot');
        expect(Family::findOrFail($familyId)->name)->toBe('Leased D1 fenced');
        $remote = DB::connection('d1-staging');
        assert($remote instanceof D1StagingConnection);
        expect($remote->integerValue('select count(*) from d1_staging_leases where scope=? and token=?', [$scope, $token]))->toBe(1);
    } finally {
        DB::connection('d1-staging')->affectingStatement('delete from d1_staging_leases where scope=? and token=?', [$scope, $token]);
    }
});
