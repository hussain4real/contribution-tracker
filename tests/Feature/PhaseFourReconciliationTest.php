<?php

declare(strict_types=1);

use App\Actions\ReverseExpense;
use App\Actions\ReverseFundAdjustment;
use App\Actions\ReversePaymentBatch;
use App\Enums\BankTransactionDirection;
use App\Enums\PaymentSource;
use App\Enums\ReconciliationPeriodStatus;
use App\Enums\ReconciliationStatus;
use App\Enums\Role;
use App\Enums\TransactionStatus;
use App\Models\AuditEvent;
use App\Models\BankTransaction;
use App\Models\Expense;
use App\Models\Family;
use App\Models\FundAdjustment;
use App\Models\PaymentBatch;
use App\Models\PaystackTransaction;
use App\Models\PlatformPlan;
use App\Models\ProviderSettlementGroup;
use App\Models\ProviderSettlementItem;
use App\Models\ReconciliationImport;
use App\Models\ReconciliationLink;
use App\Models\ReconciliationPeriod;
use App\Models\User;
use App\Services\BankStatementImportService;
use App\Services\ProviderSettlementService;
use App\Services\ReconciliationLinkService;
use App\Services\ReconciliationMatchingService;
use App\Services\ReconciliationPeriodService;
use App\Services\ReconciliationWorkspaceService;
use App\Support\PlatformPlanCatalog;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

/** @return array{Family, User, User, User} */
function phaseFourFixture(): array
{
    $family = Family::factory()->create(['currency' => 'NGN']);
    $admin = User::factory()->admin()->create(['family_id' => $family->id]);
    $secretary = User::factory()->financialSecretary()->create(['family_id' => $family->id]);
    $member = User::factory()->member()->create(['family_id' => $family->id]);

    return [$family, $admin, $secretary, $member];
}

function reconciliationImport(Family $family, ?User $actor = null): ReconciliationImport
{
    return ReconciliationImport::factory()->create([
        'family_id' => $family->id,
        'uploaded_by' => $actor?->id,
    ]);
}

it('requires the reports subscription feature for reconciliation routes', function () {
    $plan = PlatformPlan::query()->create([
        'name' => 'Reconciliation Restricted',
        'slug' => 'reconciliation-restricted',
        'price' => 0,
        'max_members' => 10,
        'features' => [PlatformPlanCatalog::BasicContributions, PlatformPlanCatalog::ManualPayments],
        'is_active' => true,
        'sort_order' => 99,
    ]);
    [$family, $admin] = phaseFourFixture();
    $family->forceFill(['platform_plan_id' => $plan->id])->save();

    $this->actingAs($admin)
        ->getJson(route('reconciliation.index', ['current_family' => $family->slug]))
        ->assertForbidden()
        ->assertJsonPath('message', 'This feature is not available on your current plan. Please upgrade.');
});

it('previews private statements and imports duplicate rows harmlessly with exact-only auto matching', function () {
    Storage::fake('local');
    [$family, $admin] = phaseFourFixture();
    PaymentBatch::factory()->create([
        'family_id' => $family->id,
        'total_amount' => 5000,
        'paid_at' => '2026-07-01',
        'reference' => 'PSK-EXACT',
        'source' => PaymentSource::Paystack,
        'recorded_by' => $admin->id,
    ]);
    Expense::factory()->recordedBy($admin)->create([
        'family_id' => $family->id,
        'amount' => 1200,
        'spent_at' => '2026-07-02',
        'description' => 'Venue hire',
    ]);
    $csv = implode("\n", [
        'Date,Amount,Direction,Reference,Description,Account',
        '2026-07-01,5000,Credit,PSK-EXACT,Member payment,Main',
        '2026-07-02,1200,Debit,,Venue hire,Main',
    ]);
    $upload = UploadedFile::fake()->createWithContent('statement.csv', $csv);
    $service = app(BankStatementImportService::class);
    $preview = $service->preview($family, $admin, $upload);

    expect($preview->headers)->toBe(['Date', 'Amount', 'Direction', 'Reference', 'Description', 'Account'])
        ->and($preview->preview_rows)->toHaveCount(2)
        ->and($preview->path)->toStartWith("reconciliation/{$family->id}/");
    Storage::disk('local')->assertExists($preview->path);
    Storage::disk('public')->assertMissing($preview->path);

    $mapping = [
        'date' => 'Date', 'amount' => 'Amount', 'direction' => 'Direction',
        'credit' => null, 'debit' => null, 'reference' => 'Reference',
        'description' => 'Description', 'source_account' => 'Account',
    ];
    $first = $service->import($preview, $admin, $mapping);
    $second = $service->import($preview->refresh(), $admin, $mapping);
    $credit = BankTransaction::query()->where('reference', 'PSK-EXACT')->firstOrFail();
    $debit = BankTransaction::query()->where('direction', BankTransactionDirection::Debit)->firstOrFail();

    expect($first)->toBe(['rows' => 2, 'imported' => 2, 'duplicates' => 0])
        ->and($second)->toBe($first)
        ->and(BankTransaction::query()->count())->toBe(2)
        ->and($credit->status)->toBe(ReconciliationStatus::Matched)
        ->and($credit->links()->count())->toBe(1)
        ->and($debit->status)->toBe(ReconciliationStatus::Suggested)
        ->and($debit->links()->count())->toBe(0)
        ->and(AuditEvent::query()->where('action', 'reconciliation.import.completed')->exists())->toBeTrue();
});

it('validates uploads and column mappings through officer-only endpoints', function () {
    Storage::fake('local');
    [$family, $admin, $secretary, $member] = phaseFourFixture();
    $csv = "Date,Credit,Debit,Reference\n2026-07-01,1000,,REF-1\n";

    $this->actingAs($member)
        ->post(route('reconciliation.imports.store', ['current_family' => $family->slug]), [
            'statement' => UploadedFile::fake()->createWithContent('statement.csv', $csv),
        ])->assertForbidden();

    $this->actingAs($secretary)
        ->post(route('reconciliation.imports.store', ['current_family' => $family->slug]), [
            'statement' => UploadedFile::fake()->createWithContent('statement.pdf', '%PDF fake'),
        ])->assertSessionHasErrors('statement');

    $response = $this->actingAs($admin)
        ->post(route('reconciliation.imports.store', ['current_family' => $family->slug]), [
            'statement' => UploadedFile::fake()->createWithContent('statement.csv', $csv),
        ])->assertRedirect();
    $import = ReconciliationImport::query()->firstOrFail();
    $response->assertRedirect(route('reconciliation.index', [
        'current_family' => $family->slug,
        'preview_import' => $import->id,
    ]));

    $this->post(route('reconciliation.imports.commit', [
        'current_family' => $family->slug,
        'reconciliation_import' => $import,
    ]), [
        'mapping' => ['date' => 'Date', 'amount' => 'Missing', 'direction' => null],
    ])->assertSessionHasErrors('mapping.amount');

    $this->post(route('reconciliation.imports.commit', [
        'current_family' => $family->slug,
        'reconciliation_import' => $import,
    ]), [
        'mapping' => ['date' => 'Date', 'credit' => 'Credit', 'debit' => null],
    ])->assertSessionHasErrors('mapping.amount');

    $this->post(route('reconciliation.imports.commit', [
        'current_family' => $family->slug,
        'reconciliation_import' => $import,
    ]), [
        'mapping' => ['date' => 'Date', 'credit' => 'Credit', 'debit' => 'Debit', 'reference' => 'Reference'],
    ])->assertRedirect(route('reconciliation.index', ['current_family' => $family->slug]));

    expect(BankTransaction::query()->firstOrFail()->direction)->toBe(BankTransactionDirection::Credit);
});

it('supports one-to-one many-to-one and split links without over-allocation', function () {
    [$family, $admin] = phaseFourFixture();
    $import = reconciliationImport($family, $admin);
    $batch = PaymentBatch::factory()->create(['family_id' => $family->id, 'total_amount' => 1000, 'recorded_by' => $admin->id]);
    $first = BankTransaction::factory()->create([
        'family_id' => $family->id, 'reconciliation_import_id' => $import->id,
        'amount' => 600, 'reference' => null,
    ]);
    $second = BankTransaction::factory()->create([
        'family_id' => $family->id, 'reconciliation_import_id' => $import->id,
        'amount' => 400, 'reference' => null, 'row_fingerprint' => hash('sha256', 'second'),
    ]);
    $splitBatch = PaymentBatch::factory()->create([
        'family_id' => $family->id, 'total_amount' => 1000, 'recorded_by' => $admin->id, 'receipt_number' => 2,
    ]);
    $splitExpense = FundAdjustment::factory()->recordedBy($admin)->create([
        'family_id' => $family->id, 'amount' => 500,
    ]);
    $third = BankTransaction::factory()->create([
        'family_id' => $family->id, 'reconciliation_import_id' => $import->id,
        'amount' => 1500, 'reference' => null, 'row_fingerprint' => hash('sha256', 'third'),
    ]);
    $links = app(ReconciliationLinkService::class);

    $links->link($first, PaymentBatch::MORPH_TYPE, $batch->id, 600, $admin);
    $links->link($second, PaymentBatch::MORPH_TYPE, $batch->id, 400, $admin);
    $links->link($third, PaymentBatch::MORPH_TYPE, $splitBatch->id, 1000, $admin);
    $links->link($third, FundAdjustment::MORPH_TYPE, $splitExpense->id, 500, $admin);

    expect($batch->reconciliationLinks()->sum('amount'))->toBe(1000)
        ->and($first->refresh()->status)->toBe(ReconciliationStatus::Matched)
        ->and($third->refresh()->links()->count())->toBe(2)
        ->and($third->status)->toBe(ReconciliationStatus::Matched)
        ->and(fn () => $links->link($second, PaymentBatch::MORPH_TYPE, $splitBatch->id, 1, $admin))
        ->toThrow(InvalidArgumentException::class);
});

it('requires reconciled ledger entries to be unlinked before reversal', function () {
    [$family, $admin] = phaseFourFixture();
    $import = reconciliationImport($family, $admin);
    $batch = PaymentBatch::factory()->create([
        'family_id' => $family->id,
        'recorded_by' => $admin->id,
        'total_amount' => 1000,
        'paid_at' => '2026-07-10',
        'reference' => 'SETTLED-DIRECT',
    ]);
    $expense = Expense::factory()->recordedBy($admin)->create([
        'family_id' => $family->id,
        'amount' => 600,
    ]);
    $adjustment = FundAdjustment::factory()->recordedBy($admin)->create([
        'family_id' => $family->id,
        'amount' => -400,
    ]);
    $credit = BankTransaction::factory()->create([
        'family_id' => $family->id,
        'reconciliation_import_id' => $import->id,
        'amount' => 1000,
    ]);
    $debit = BankTransaction::factory()->create([
        'family_id' => $family->id,
        'reconciliation_import_id' => $import->id,
        'direction' => BankTransactionDirection::Debit,
        'amount' => 1000,
        'row_fingerprint' => hash('sha256', 'reversal-debit'),
    ]);
    $links = app(ReconciliationLinkService::class);
    $links->link($credit, PaymentBatch::MORPH_TYPE, $batch->id, 1000, $admin);
    $links->link($debit, Expense::MORPH_TYPE, $expense->id, 600, $admin);
    $links->link($debit, FundAdjustment::MORPH_TYPE, $adjustment->id, 400, $admin);

    expect(fn () => app(ReversePaymentBatch::class)->handle($batch, $admin, 'Linked reversal'))
        ->toThrow(InvalidArgumentException::class, 'Remove reconciliation links')
        ->and(fn () => app(ReverseExpense::class)->handle($expense, $admin, 'Linked reversal'))
        ->toThrow(InvalidArgumentException::class, 'Remove reconciliation links')
        ->and(fn () => app(ReverseFundAdjustment::class)->handle($adjustment, $admin, 'Linked reversal'))
        ->toThrow(InvalidArgumentException::class, 'Remove reconciliation links');
});

it('matches debits to expenses and negative adjustments while enforcing direction and family isolation', function () {
    [$family, $admin] = phaseFourFixture();
    $otherFamily = Family::factory()->create();
    $import = reconciliationImport($family, $admin);
    $debit = BankTransaction::factory()->create([
        'family_id' => $family->id, 'reconciliation_import_id' => $import->id,
        'direction' => BankTransactionDirection::Debit, 'amount' => 700,
    ]);
    $expense = Expense::factory()->recordedBy($admin)->create(['family_id' => $family->id, 'amount' => 400]);
    $negative = FundAdjustment::factory()->recordedBy($admin)->create(['family_id' => $family->id, 'amount' => -300]);
    $credit = PaymentBatch::factory()->create(['family_id' => $family->id, 'total_amount' => 700, 'recorded_by' => $admin->id]);
    $otherExpense = Expense::factory()->create(['family_id' => $otherFamily->id, 'amount' => 700]);
    $links = app(ReconciliationLinkService::class);

    $links->link($debit, Expense::MORPH_TYPE, $expense->id, 400, $admin);
    $links->link($debit, FundAdjustment::MORPH_TYPE, $negative->id, 300, $admin);

    expect($debit->refresh()->status)->toBe(ReconciliationStatus::Matched)
        ->and(fn () => $links->link(BankTransaction::factory()->create([
            'family_id' => $family->id, 'reconciliation_import_id' => $import->id,
            'direction' => BankTransactionDirection::Debit, 'amount' => 700,
            'row_fingerprint' => hash('sha256', 'wrong-direction'),
        ]), PaymentBatch::MORPH_TYPE, $credit->id, 700, $admin))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $links->link(BankTransaction::factory()->create([
            'family_id' => $family->id, 'reconciliation_import_id' => $import->id,
            'direction' => BankTransactionDirection::Debit, 'amount' => 700,
            'row_fingerprint' => hash('sha256', 'cross-family'),
        ]), Expense::MORPH_TYPE, $otherExpense->id, 700, $admin))->toThrow(InvalidArgumentException::class);
});

it('groups Paystack gross fees and net settlements while reporting bank differences separately', function () {
    [$family, $admin] = phaseFourFixture();
    $import = reconciliationImport($family, $admin);
    $bank = BankTransaction::factory()->create([
        'family_id' => $family->id, 'reconciliation_import_id' => $import->id,
        'amount' => 1950, 'reference' => 'SETTLEMENT-1',
    ]);
    $transactions = collect([1000, 1000])->map(function (int $amount, int $index) use ($family, $admin): PaystackTransaction {
        $batch = PaymentBatch::factory()->create([
            'family_id' => $family->id, 'total_amount' => $amount,
            'recorded_by' => $admin->id, 'receipt_number' => $index + 1,
        ]);

        return PaystackTransaction::factory()->create([
            'family_id' => $family->id,
            'user_id' => $admin->id,
            'payment_batch_id' => $batch->id,
            'amount' => $amount,
            'gross_amount_kobo' => $amount * 100,
            'actual_fee_kobo' => 2000,
            'settled_amount_kobo' => ($amount - 20) * 100,
        ]);
    });

    $transactionIds = array_values($transactions
        ->map(fn (PaystackTransaction $transaction): int => $transaction->id)
        ->all());
    $group = app(ProviderSettlementService::class)->create(
        $family->id,
        $transactionIds,
        'SETTLEMENT-1',
        '2026-07-10',
        $admin,
        $bank,
    );

    expect($group->gross_amount)->toBe(2000)
        ->and($group->fee_amount)->toBe(40)
        ->and($group->net_amount)->toBe(1960)
        ->and($group->bank_amount)->toBe(1950)
        ->and($group->difference)->toBe(-10)
        ->and($group->items)->toHaveCount(2)
        ->and($bank->refresh()->status)->toBe(ReconciliationStatus::Matched)
        ->and(fn () => app(ProviderSettlementService::class)->create(
            $family->id, $transactionIds, 'SETTLEMENT-2', '2026-07-11', $admin,
        ))->toThrow(InvalidArgumentException::class);

    $this->actingAs($admin)
        ->from(route('reconciliation.index', ['current_family' => $family->slug]))
        ->post(route('reconciliation.settlements.store', ['current_family' => $family->slug]), [
            'reference' => 'SETTLEMENT-1',
            'settled_at' => '2026-07-11',
            'paystack_transaction_ids' => $transactionIds,
        ])
        ->assertSessionHasErrors('reference');

    expect(ProviderSettlementGroup::query()->where('family_id', $family->id)->count())->toBe(1);
});

it('rejects reversed Paystack transactions from settlement groups', function () {
    [$family, $admin] = phaseFourFixture();
    $batch = PaymentBatch::factory()->create([
        'family_id' => $family->id,
        'recorded_by' => $admin->id,
    ]);
    $transaction = PaystackTransaction::factory()->create([
        'family_id' => $family->id,
        'user_id' => $admin->id,
        'payment_batch_id' => $batch->id,
        'status' => TransactionStatus::Reversed,
    ]);

    $workspace = app(ReconciliationWorkspaceService::class)->data($family, $admin, []);

    expect($workspace['paystack_transactions'])->toBeEmpty()
        ->and(fn () => app(ProviderSettlementService::class)->create(
            $family->id,
            [$transaction->id],
            'REVERSED-SETTLEMENT',
            '2026-07-10',
            $admin,
        ))->toThrow(InvalidArgumentException::class, 'Every Paystack transaction must be allocated in this family.');
});

it('keeps settled Paystack receipts exclusive to their settlement groups', function () {
    [$family, $admin] = phaseFourFixture();
    $import = reconciliationImport($family, $admin);
    $batch = PaymentBatch::factory()->create([
        'family_id' => $family->id,
        'recorded_by' => $admin->id,
        'total_amount' => 1000,
    ]);
    $transaction = PaystackTransaction::factory()->create([
        'family_id' => $family->id,
        'user_id' => $admin->id,
        'payment_batch_id' => $batch->id,
    ]);
    $group = ProviderSettlementGroup::factory()->create([
        'family_id' => $family->id,
        'created_by' => $admin->id,
    ]);
    ProviderSettlementItem::factory()->create([
        'provider_settlement_group_id' => $group->id,
        'paystack_transaction_id' => $transaction->id,
        'payment_batch_id' => $batch->id,
    ]);
    $bank = BankTransaction::factory()->create([
        'family_id' => $family->id,
        'reconciliation_import_id' => $import->id,
        'amount' => 1000,
        'transacted_at' => '2026-07-10',
        'reference' => 'SETTLED-DIRECT',
    ]);
    $matching = app(ReconciliationMatchingService::class);

    expect(fn () => app(ReversePaymentBatch::class)->handle($batch, $admin, 'Settled reversal'))
        ->toThrow(InvalidArgumentException::class, 'settled Paystack receipt')
        ->and(fn () => app(ReconciliationLinkService::class)->link(
            $bank,
            PaymentBatch::MORPH_TYPE,
            $batch->id,
            1000,
            $admin,
        ))->toThrow(InvalidArgumentException::class, 'settled Paystack receipt')
        ->and($matching->exactCandidates($bank))->toBeEmpty()
        ->and($matching->suggestions($bank)->pluck('id'))->not->toContain($batch->id);
});

it('requires direct receipt links to be removed before Paystack settlement grouping', function () {
    [$family, $admin] = phaseFourFixture();
    $import = reconciliationImport($family, $admin);
    $batch = PaymentBatch::factory()->create([
        'family_id' => $family->id,
        'recorded_by' => $admin->id,
        'total_amount' => 1000,
    ]);
    $transaction = PaystackTransaction::factory()->create([
        'family_id' => $family->id,
        'user_id' => $admin->id,
        'payment_batch_id' => $batch->id,
    ]);
    $bank = BankTransaction::factory()->create([
        'family_id' => $family->id,
        'reconciliation_import_id' => $import->id,
        'amount' => 1000,
    ]);
    app(ReconciliationLinkService::class)->link($bank, PaymentBatch::MORPH_TYPE, $batch->id, 1000, $admin);

    expect(fn () => app(ProviderSettlementService::class)->create(
        $family->id,
        [$transaction->id],
        'DIRECT-LINK',
        '2026-07-10',
        $admin,
    ))->toThrow(InvalidArgumentException::class, 'Remove direct receipt reconciliation links');
});

it('rejects a fully reconciled bank transaction selected for a settlement group', function () {
    [$family, $admin] = phaseFourFixture();
    $import = reconciliationImport($family, $admin);
    $bank = BankTransaction::factory()->create([
        'family_id' => $family->id,
        'reconciliation_import_id' => $import->id,
        'amount' => 1000,
    ]);
    $alreadyLinkedBatch = PaymentBatch::factory()->create([
        'family_id' => $family->id,
        'recorded_by' => $admin->id,
        'total_amount' => 1000,
    ]);
    app(ReconciliationLinkService::class)->link($bank, PaymentBatch::MORPH_TYPE, $alreadyLinkedBatch->id, 1000, $admin);
    $settlementBatch = PaymentBatch::factory()->create([
        'family_id' => $family->id,
        'recorded_by' => $admin->id,
        'total_amount' => 1000,
        'receipt_number' => 2,
    ]);
    $transaction = PaystackTransaction::factory()->create([
        'family_id' => $family->id,
        'user_id' => $admin->id,
        'payment_batch_id' => $settlementBatch->id,
    ]);

    expect(fn () => app(ProviderSettlementService::class)->create(
        $family->id,
        [$transaction->id],
        'EXHAUSTED-BANK',
        '2026-07-10',
        $admin,
        $bank,
    ))->toThrow(InvalidArgumentException::class, 'already fully reconciled');

    $this->assertDatabaseMissing('provider_settlement_groups', [
        'family_id' => $family->id,
        'reference' => 'EXHAUSTED-BANK',
    ]);
});

it('blocks ledger postings and reversals in closed reconciliation periods', function () {
    [$family, $admin] = phaseFourFixture();
    $batch = PaymentBatch::factory()->create([
        'family_id' => $family->id,
        'recorded_by' => $admin->id,
        'paid_at' => '2026-07-10',
    ]);
    $expense = Expense::factory()->recordedBy($admin)->create([
        'family_id' => $family->id,
        'spent_at' => '2026-07-11',
    ]);
    $adjustment = FundAdjustment::factory()->recordedBy($admin)->create([
        'family_id' => $family->id,
        'recorded_at' => '2026-07-12',
    ]);
    $period = app(ReconciliationPeriodService::class)->create($family, '2026-07-01', '2026-07-31');
    app(ReconciliationPeriodService::class)->close($period, $admin);

    expect(fn () => PaymentBatch::factory()->create([
        'family_id' => $family->id,
        'recorded_by' => $admin->id,
        'paid_at' => '2026-07-20',
    ]))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Expense::factory()->recordedBy($admin)->create([
            'family_id' => $family->id,
            'spent_at' => '2026-07-20',
        ]))->toThrow(InvalidArgumentException::class)
        ->and(fn () => FundAdjustment::factory()->recordedBy($admin)->create([
            'family_id' => $family->id,
            'recorded_at' => '2026-07-20',
        ]))->toThrow(InvalidArgumentException::class)
        ->and(fn () => app(ReversePaymentBatch::class)->handle($batch, $admin, 'Closed period reversal'))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => app(ReverseExpense::class)->handle($expense, $admin, 'Closed period reversal'))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => app(ReverseFundAdjustment::class)->handle($adjustment, $admin, 'Closed period reversal'))
        ->toThrow(InvalidArgumentException::class);
});

it('closes reproducible period snapshots and requires an audited admin reason to reopen', function () {
    [$family, $admin, $secretary] = phaseFourFixture();
    $import = reconciliationImport($family, $admin);
    $bank = BankTransaction::factory()->create([
        'family_id' => $family->id, 'reconciliation_import_id' => $import->id,
        'transacted_at' => '2026-07-12', 'amount' => 1000,
    ]);
    BankTransaction::factory()->create([
        'family_id' => $family->id, 'reconciliation_import_id' => $import->id,
        'transacted_at' => '2026-07-13', 'amount' => 900,
        'direction' => BankTransactionDirection::Credit, 'status' => ReconciliationStatus::Ignored,
    ]);
    BankTransaction::factory()->create([
        'family_id' => $family->id, 'reconciliation_import_id' => $import->id,
        'transacted_at' => '2026-07-14', 'amount' => 400,
        'direction' => BankTransactionDirection::Debit, 'status' => ReconciliationStatus::Ignored,
    ]);
    $batch = PaymentBatch::factory()->create([
        'family_id' => $family->id, 'total_amount' => 1000, 'paid_at' => '2026-07-12', 'recorded_by' => $admin->id,
    ]);
    FundAdjustment::factory()->recordedBy($admin)->create([
        'family_id' => $family->id, 'amount' => 1000, 'recorded_at' => '2026-07-12',
    ]);
    $period = app(ReconciliationPeriodService::class)->create($family, '2026-07-01', '2026-07-31');
    app(ReconciliationLinkService::class)->link($bank, PaymentBatch::MORPH_TYPE, $batch->id, 1000, $admin);
    $closed = app(ReconciliationPeriodService::class)->close($period, $secretary);
    $snapshot = $closed->only(['opening_balance', 'closing_balance', 'bank_net', 'ledger_net', 'variance']);

    expect($closed->status)->toBe(ReconciliationPeriodStatus::Closed)
        ->and($snapshot)->toBe([
            'opening_balance' => 0, 'closing_balance' => 1000,
            'bank_net' => 1000, 'ledger_net' => 1000, 'variance' => 0,
        ])
        ->and(fn () => app(ReconciliationLinkService::class)->unlink($bank->links()->firstOrFail(), $admin))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => $closed->forceFill(['variance' => 99])->save())
        ->toThrow(LogicException::class);

    $this->actingAs($secretary)
        ->post(route('reconciliation.periods.reopen', ['current_family' => $family->slug, 'reconciliation_period' => $closed]), ['reason' => 'Need correction'])
        ->assertForbidden();
    $this->actingAs($admin)
        ->post(route('reconciliation.periods.reopen', ['current_family' => $family->slug, 'reconciliation_period' => $closed]), ['reason' => 'Bank supplied a corrected statement'])
        ->assertRedirect();

    $reopened = $closed->refresh();
    expect($reopened->status)->toBe(ReconciliationPeriodStatus::Reopened)
        ->and($reopened->only(array_keys($snapshot)))->toBe($snapshot)
        ->and(AuditEvent::query()->where('action', 'reconciliation.period.reopened')->exists())->toBeTrue();

    $reclosed = app(ReconciliationPeriodService::class)->close($reopened, $secretary);
    expect($reclosed->status)->toBe(ReconciliationPeriodStatus::Closed)
        ->and($reclosed->only(array_keys($snapshot)))->toBe($snapshot);
});

it('scopes reconciliation mutations to the family in the route', function () {
    [$routeFamily, $admin] = phaseFourFixture();
    $otherFamily = Family::factory()->create();
    $admin->ensureFamilyMembership($otherFamily, Role::Member);
    $import = reconciliationImport($otherFamily, $admin);
    $bank = BankTransaction::factory()->create([
        'family_id' => $otherFamily->id,
        'reconciliation_import_id' => $import->id,
    ]);
    $link = ReconciliationLink::factory()->create([
        'family_id' => $otherFamily->id,
        'bank_transaction_id' => $bank->id,
    ]);
    $period = ReconciliationPeriod::factory()->create(['family_id' => $otherFamily->id]);

    $this->actingAs($admin)
        ->post(route('reconciliation.imports.commit', [
            'current_family' => $routeFamily->slug,
            'reconciliation_import' => $import,
        ]), ['mapping' => []])
        ->assertForbidden();

    $this->post(route('reconciliation.links.store', [
        'current_family' => $routeFamily->slug,
        'bank_transaction' => $bank,
    ]), [])->assertForbidden();

    $this->delete(route('reconciliation.links.destroy', [
        'current_family' => $routeFamily->slug,
        'reconciliation_link' => $link,
    ]))->assertForbidden();

    $this->patch(route('reconciliation.transactions.status', [
        'current_family' => $routeFamily->slug,
        'bank_transaction' => $bank,
    ]), [])->assertForbidden();

    $this->post(route('reconciliation.periods.close', [
        'current_family' => $routeFamily->slug,
        'reconciliation_period' => $period,
    ]))->assertForbidden();

    $this->post(route('reconciliation.periods.reopen', [
        'current_family' => $routeFamily->slug,
        'reconciliation_period' => $period,
    ]), [])->assertForbidden();
});

it('renders every reconciliation queue for officers and blocks members and outsiders', function () {
    [$family, $admin, , $member] = phaseFourFixture();
    $otherFamily = Family::factory()->create();
    $outsider = User::factory()->admin()->create(['family_id' => $otherFamily->id]);
    $import = reconciliationImport($family, $admin);

    foreach (ReconciliationStatus::cases() as $index => $status) {
        BankTransaction::factory()->create([
            'family_id' => $family->id,
            'reconciliation_import_id' => $import->id,
            'status' => $status,
            'row_fingerprint' => hash('sha256', "queue-{$index}"),
        ]);
    }

    $this->actingAs($admin)
        ->get(route('reconciliation.index', ['current_family' => $family->slug]))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('Reconciliation/Index')
            ->where('summary.unmatched', 1)
            ->where('summary.suggested', 1)
            ->where('summary.matched', 1)
            ->where('summary.ignored', 1)
            ->where('summary.disputed', 1));

    $this->actingAs($member)->get(route('reconciliation.index', ['current_family' => $family->slug]))->assertForbidden();
    $this->actingAs($outsider)->get(route('reconciliation.index', ['current_family' => $family->slug]))->assertForbidden();
});
