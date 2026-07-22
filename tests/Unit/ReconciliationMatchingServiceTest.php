<?php

declare(strict_types=1);

use App\Enums\ReconciliationStatus;
use App\Models\BankTransaction;
use App\Models\Family;
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
});
