<?php

declare(strict_types=1);

use App\Enums\BankTransactionDirection;
use App\Enums\ReconciliationStatus;
use App\Models\BankTransaction;
use App\Models\Expense;
use App\Models\Family;
use App\Models\FundAdjustment;
use App\Models\PaymentBatch;
use App\Models\ReconciliationImport;
use App\Models\User;
use App\Services\ReconciliationMatchingService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, LazilyRefreshDatabase::class);

it('auto links only a unique exact reference and leaves ambiguous exact matches unlinked', function () {
    $family = Family::factory()->create();
    $admin = User::factory()->admin()->create(['family_id' => $family->id]);
    $import = ReconciliationImport::factory()->create(['family_id' => $family->id]);
    PaymentBatch::factory()->count(2)->sequence(
        ['receipt_number' => 1],
        ['receipt_number' => 2],
    )->create([
        'family_id' => $family->id,
        'total_amount' => 1000,
        'reference' => 'DUPLICATE-REF',
        'recorded_by' => $admin->id,
    ]);
    $ambiguous = BankTransaction::factory()->create([
        'family_id' => $family->id,
        'reconciliation_import_id' => $import->id,
        'amount' => 1000,
        'reference' => 'duplicate-ref',
    ]);
    $uniqueBatch = PaymentBatch::factory()->create([
        'family_id' => $family->id,
        'total_amount' => 1500,
        'reference' => 'UNIQUE-REF',
        'recorded_by' => $admin->id,
        'receipt_number' => 3,
    ]);
    $unique = BankTransaction::factory()->create([
        'family_id' => $family->id,
        'reconciliation_import_id' => $import->id,
        'amount' => 1500,
        'reference' => 'unique-ref',
        'row_fingerprint' => hash('sha256', 'unique'),
    ]);
    $service = app(ReconciliationMatchingService::class);

    expect($service->autoMatch($ambiguous, $admin))->toBeNull()
        ->and($ambiguous->refresh()->links()->count())->toBe(0)
        ->and($service->autoMatch($unique, $admin)?->is($uniqueBatch))->toBeTrue()
        ->and($unique->refresh()->status)->toBe(ReconciliationStatus::Matched)
        ->and($unique->links()->count())->toBe(1);

    $exhaustedBatch = PaymentBatch::factory()->create([
        'family_id' => $family->id,
        'total_amount' => 2000,
        'paid_at' => '2026-07-15',
        'reference' => 'EXHAUSTED-REF',
        'recorded_by' => $admin->id,
        'receipt_number' => 4,
    ]);
    $matchedBank = BankTransaction::factory()->create([
        'family_id' => $family->id,
        'reconciliation_import_id' => $import->id,
        'amount' => 2000,
        'transacted_at' => '2026-07-15',
        'reference' => 'exhausted-ref',
        'row_fingerprint' => hash('sha256', 'matched-exhausted'),
    ]);
    $duplicateBank = BankTransaction::factory()->create([
        'family_id' => $family->id,
        'reconciliation_import_id' => $import->id,
        'amount' => 2000,
        'transacted_at' => '2026-07-15',
        'reference' => 'exhausted-ref',
        'row_fingerprint' => hash('sha256', 'duplicate-exhausted'),
    ]);

    expect($service->autoMatch($matchedBank, $admin)?->is($exhaustedBatch))->toBeTrue()
        ->and($service->autoMatch($duplicateBank, $admin))->toBeNull()
        ->and($duplicateBank->refresh()->status)->toBe(ReconciliationStatus::Suggested)
        ->and($duplicateBank->links()->count())->toBe(0);
});

it('batches suggestions for mixed reconciliation queues', function () {
    $family = Family::factory()->create();
    $admin = User::factory()->admin()->create(['family_id' => $family->id]);
    $import = ReconciliationImport::factory()->create(['family_id' => $family->id]);
    $credit = BankTransaction::factory()->create([
        'family_id' => $family->id,
        'reconciliation_import_id' => $import->id,
        'direction' => BankTransactionDirection::Credit,
        'amount' => 1200,
        'transacted_at' => '2026-07-15',
    ]);
    $debit = BankTransaction::factory()->create([
        'family_id' => $family->id,
        'reconciliation_import_id' => $import->id,
        'direction' => BankTransactionDirection::Debit,
        'amount' => 700,
        'transacted_at' => '2026-07-16',
    ]);
    $batch = PaymentBatch::factory()->create([
        'family_id' => $family->id,
        'recorded_by' => $admin->id,
        'total_amount' => 1200,
        'paid_at' => '2026-07-14',
    ]);
    $expense = Expense::factory()->recordedBy($admin)->create([
        'family_id' => $family->id,
        'amount' => 700,
        'spent_at' => '2026-07-17',
    ]);
    $positiveAdjustment = FundAdjustment::factory()->recordedBy($admin)->create([
        'family_id' => $family->id,
        'amount' => 1200,
        'recorded_at' => '2026-07-15',
    ]);
    $negativeAdjustment = FundAdjustment::factory()->recordedBy($admin)->create([
        'family_id' => $family->id,
        'amount' => -700,
        'recorded_at' => '2026-07-16',
    ]);
    $service = app(ReconciliationMatchingService::class);
    $suggestions = $service->suggestionsFor(collect([$credit, $debit]));

    expect($service->suggestionsFor(BankTransaction::query()->whereRaw('1 = 0')->get()))->toBe([])
        ->and(collect($suggestions[$credit->id])->pluck('id'))->toContain($batch->id, $positiveAdjustment->id)
        ->and(collect($suggestions[$debit->id])->pluck('id'))->toContain($expense->id, $negativeAdjustment->id);
});
