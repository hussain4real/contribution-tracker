<?php

declare(strict_types=1);

use App\Actions\CorrectPaymentBatch;
use App\Actions\ReversePaymentBatch;
use App\Enums\PaymentMethod;
use App\Models\Contribution;
use App\Models\Family;
use App\Models\User;
use App\Services\PaymentAllocationPreview;
use App\Services\PaymentAllocationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

pest()->extend(TestCase::class);

it('keeps allocations correct when a nested transaction waits for the family lock', function (string $scenario): void {
    if (getenv('FAMILYFUND_MYSQL_CONCURRENCY_TEST') !== '1'
        || config('database.default') !== 'mysql'
        || config('database.connections.mysql.database') !== 'familyfund_concurrency_test'
        || ! function_exists('pcntl_fork')) {
        $this->markTestSkipped('Requires the explicitly configured disposable MySQL concurrency database and pcntl.');
    }

    $family = Family::factory()->create();
    $member = User::factory()->member()->employed()->create(['family_id' => $family->id]);
    $officer = User::factory()->financialSecretary()->create(['family_id' => $family->id]);
    $contribution = Contribution::factory()->forUser($member)->currentMonth()->create(['expected_amount' => 4000]);
    $membership = $member->membershipForFamily($family);
    if ($membership === null) {
        throw new RuntimeException('The test member has no family membership.');
    }
    $sameRequest = $scenario === 'same';
    $reviewAmount = $sameRequest ? 1000 : ($scenario === 'stale-review' ? 2000 : null);
    $review = $reviewAmount === null ? null : app(PaymentAllocationPreview::class)->preview($member, $family, $membership, $reviewAmount)['allocations'];
    $ids = [$family->id, $member->id, $officer->id];
    $key = (string) Str::uuid();
    DB::disconnect();
    $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    if ($sockets === false) {
        throw new RuntimeException('Could not create the concurrency test socket.');
    }
    $pid = pcntl_fork();
    if ($pid === -1) {
        throw new RuntimeException('Could not start the concurrent payment process.');
    }
    if ($pid === 0) {
        fclose($sockets[0]);
        DB::purge();
        try {
            fgets($sockets[1]);
            $connectionId = DB::scalar('select connection_id()');
            if (! is_int($connectionId) && ! is_string($connectionId)) {
                throw new RuntimeException('Could not identify the concurrent MySQL connection.');
            }
            fwrite($sockets[1], $connectionId."\n");
            $result = DB::transaction(function () use ($ids, $key, $review, $sameRequest) {
                DB::table('payment_batches')->count();

                return app(PaymentAllocationService::class)->createBatch(
                    member: User::findOrFail($ids[1]), amount: $sameRequest ? 1000 : 2000, paidAt: '2026-10-30',
                    recordedBy: User::findOrFail($ids[2]), family: Family::findOrFail($ids[0]),
                    idempotencyKey: $sameRequest ? $key : $key.'-other', expectedAllocations: $review,
                );
            });
            fwrite($sockets[1], json_encode(['id' => $result->id], JSON_THROW_ON_ERROR)."\n");
        } catch (Throwable $exception) {
            fwrite($sockets[1], json_encode(['error' => $exception->getMessage()], JSON_THROW_ON_ERROR)."\n");
        }
        fclose($sockets[1]);
        exit(0);
    }

    fclose($sockets[1]);
    stream_set_timeout($sockets[0], 10);
    DB::purge();
    DB::beginTransaction();
    try {
        Family::query()->lockForUpdate()->findOrFail($ids[0]);
        fwrite($sockets[0], "go\n");
        $childId = (int) trim((string) fgets($sockets[0]));
        $waiting = false;
        for ($attempt = 0; $attempt < 200; $attempt++) {
            $queryText = DB::table('information_schema.processlist')->where('id', $childId)->value('info');
            if (is_string($queryText) && str_contains(strtolower($queryText), 'for update')) {
                $waiting = true;
                break;
            }
            usleep(25000);
        }
        if (! $waiting) {
            throw new RuntimeException('The concurrent payment did not reach the family lock.');
        }
        $batch = app(PaymentAllocationService::class)->createBatch(
            member: User::findOrFail($ids[1]), amount: $sameRequest ? 1000 : 3000, paidAt: '2026-10-30',
            recordedBy: User::findOrFail($ids[2]), family: Family::findOrFail($ids[0]),
            idempotencyKey: $key, expectedAllocations: $sameRequest ? $review : null,
        );
        if ($scenario === 'reversed') {
            app(ReversePaymentBatch::class)->handle($batch, User::findOrFail($ids[2]), 'Synthetic test reversal');
        }
        DB::commit();
        $response = json_decode((string) fgets($sockets[0]), true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($response)) {
            throw new RuntimeException('Invalid result from the concurrent payment process.');
        }
        if ($sameRequest) {
            expect($response)->toBe(['id' => $batch->id]);
        } elseif ($scenario === 'stale-review') {
            expect($response)->toHaveKey('error', 'Contribution balances changed. Review the payment again before recording it.')
                ->and($contribution->fresh()?->total_paid)->toBe(3000)
                ->and(DB::table('payment_batches')->where('idempotency_key', $key.'-other')->count())->toBe(0);
        } else {
            expect($response)->toHaveKey('id')->and($response['id'])->not->toBe($batch->id)
                ->and($contribution->fresh()?->total_paid)->toBe($scenario === 'reversed' ? 2000 : 4000);
        }
        expect(DB::table('payment_batches')->where('idempotency_key', $key)->count())->toBe(1);
    } finally {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        fclose($sockets[0]);
        pcntl_waitpid($pid, $status);
    }
})->with(['same reviewed payment' => ['same'], 'different payments' => ['different'], 'payment reversed while waiting' => ['reversed'], 'stale review' => ['stale-review']]);

it('preserves the effective balance when a payment is corrected on MySQL', function (): void {
    if (getenv('FAMILYFUND_MYSQL_CONCURRENCY_TEST') !== '1'
        || config('database.default') !== 'mysql'
        || config('database.connections.mysql.database') !== 'familyfund_concurrency_test') {
        $this->markTestSkipped('Requires the explicitly configured disposable MySQL concurrency database.');
    }
    $family = Family::factory()->create();
    $member = User::factory()->member()->employed()->create(['family_id' => $family->id]);
    $officer = User::factory()->financialSecretary()->create(['family_id' => $family->id]);
    $contribution = Contribution::factory()->forUser($member)->currentMonth()->create(['expected_amount' => 4000]);
    $batch = app(PaymentAllocationService::class)->createBatch(member: $member, amount: 4000, paidAt: now(), recordedBy: $officer, family: $family);

    $replacement = app(CorrectPaymentBatch::class)->handle(original: $batch, actor: $officer, reason: 'Synthetic correction', amount: 3500, paidAt: now(), method: PaymentMethod::Cash);

    expect($replacement->allocations->sum('amount'))->toBe(3500)
        ->and($contribution->fresh()?->total_paid)->toBe(3500);
});
