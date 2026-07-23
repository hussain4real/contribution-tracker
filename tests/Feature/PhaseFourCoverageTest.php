<?php

declare(strict_types=1);

use App\Enums\BankTransactionDirection;
use App\Enums\PaymentSource;
use App\Enums\ReconciliationImportStatus;
use App\Enums\ReconciliationPeriodStatus;
use App\Enums\ReconciliationStatus;
use App\Models\BankTransaction;
use App\Models\Expense;
use App\Models\Family;
use App\Models\FundAdjustment;
use App\Models\PaymentBatch;
use App\Models\PaystackTransaction;
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
use App\Services\ReconciliationStatusService;
use App\Services\ReconciliationWorkspaceService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Mockery\MockInterface;

/** @return array{Family, User, ReconciliationImport} */
function phaseFourCoverageFixture(): array
{
    $family = Family::factory()->create(['currency' => 'NGN']);
    $admin = User::factory()->admin()->create(['family_id' => $family->id]);
    $import = ReconciliationImport::factory()->create(['family_id' => $family->id, 'uploaded_by' => $admin->id]);

    return [$family, $admin, $import];
}

/** @param Closure(MockInterface): void $configure */
function phaseFourUploadedFileMock(Closure $configure): UploadedFile
{
    $mock = Mockery::mock(UploadedFile::class);
    $configure($mock);

    if (! $mock instanceof UploadedFile) {
        throw new RuntimeException('Could not create an uploaded file mock.');
    }

    return $mock;
}

it('exercises reconciliation mutation endpoints and their validated requests', function () {
    [$family, $admin, $import] = phaseFourCoverageFixture();
    $bank = BankTransaction::factory()->create([
        'family_id' => $family->id,
        'reconciliation_import_id' => $import->id,
        'amount' => 1000,
    ]);
    $batch = PaymentBatch::factory()->create([
        'family_id' => $family->id,
        'total_amount' => 1000,
        'recorded_by' => $admin->id,
    ]);
    $this->actingAs($admin)->post(route('reconciliation.links.store', [
        'current_family' => $family->slug,
        'bank_transaction' => $bank,
    ]), [
        'target_type' => PaymentBatch::MORPH_TYPE,
        'target_id' => $batch->id,
        'amount' => 1000,
        'notes' => 'Endpoint link',
    ])->assertRedirect()->assertSessionHas('success');

    $link = ReconciliationLink::query()->firstOrFail();
    $this->delete(route('reconciliation.links.destroy', [
        'current_family' => $family->slug,
        'reconciliation_link' => $link,
    ]))->assertRedirect()->assertSessionHas('success');
    $this->patch(route('reconciliation.transactions.status', [
        'current_family' => $family->slug,
        'bank_transaction' => $bank,
    ]), ['status' => 'ignored', 'reason' => 'Duplicate bank feed row'])->assertRedirect();
    $this->patch(route('reconciliation.transactions.status', [
        'current_family' => $family->slug,
        'bank_transaction' => $bank,
    ]), ['status' => 'disputed', 'reason' => 'Bank investigation opened'])->assertRedirect();
    $this->patch(route('reconciliation.transactions.status', [
        'current_family' => $family->slug,
        'bank_transaction' => $bank,
    ]), ['status' => 'unmatched'])->assertRedirect();

    $this->post(route('reconciliation.periods.store', ['current_family' => $family->slug]), [
        'starts_at' => '2026-08-01',
        'ends_at' => '2026-08-31',
    ])->assertRedirect()->assertSessionHas('success');
    $period = ReconciliationPeriod::query()->firstOrFail();
    $this->post(route('reconciliation.periods.close', [
        'current_family' => $family->slug,
        'reconciliation_period' => $period,
    ]))->assertRedirect()->assertSessionHas('success');

    $providerBatch = PaymentBatch::factory()->create([
        'family_id' => $family->id,
        'total_amount' => 1000,
        'source' => PaymentSource::Paystack,
        'recorded_by' => $admin->id,
        'receipt_number' => 2,
    ]);
    $providerTransaction = PaystackTransaction::factory()->create([
        'family_id' => $family->id,
        'user_id' => $admin->id,
        'payment_batch_id' => $providerBatch->id,
    ]);
    $this->post(route('reconciliation.settlements.store', ['current_family' => $family->slug]), [
        'reference' => 'ENDPOINT-SETTLEMENT',
        'settled_at' => '2026-09-01',
        'paystack_transaction_ids' => [(string) $providerTransaction->id],
        'notes' => 'Provider endpoint',
    ])->assertRedirect()->assertSessionHas('success');

    expect($bank->refresh()->status)->toBe(ReconciliationStatus::Unmatched)
        ->and($period->refresh()->status)->toBe(ReconciliationPeriodStatus::Closed)
        ->and(ProviderSettlementGroup::query()->where('reference', 'ENDPOINT-SETTLEMENT')->exists())->toBeTrue();
});

it('covers reconciliation model relationships scopes and immutability', function () {
    [$family, $admin, $import] = phaseFourCoverageFixture();
    $bank = BankTransaction::factory()->create(['family_id' => $family->id, 'reconciliation_import_id' => $import->id]);
    $batch = PaymentBatch::factory()->create(['family_id' => $family->id, 'recorded_by' => $admin->id]);
    $group = ProviderSettlementGroup::factory()->create([
        'family_id' => $family->id,
        'bank_transaction_id' => $bank->id,
        'created_by' => $admin->id,
    ]);
    $providerTransaction = PaystackTransaction::factory()->create([
        'family_id' => $family->id,
        'user_id' => $admin->id,
        'payment_batch_id' => $batch->id,
    ]);
    $item = ProviderSettlementItem::factory()->create([
        'provider_settlement_group_id' => $group->id,
        'paystack_transaction_id' => $providerTransaction->id,
        'payment_batch_id' => $batch->id,
    ]);
    $link = ReconciliationLink::factory()->create([
        'family_id' => $family->id,
        'bank_transaction_id' => $bank->id,
        'reconcilable_type' => PaymentBatch::MORPH_TYPE,
        'reconcilable_id' => $batch->id,
        'created_by' => $admin->id,
    ]);
    $period = ReconciliationPeriod::factory()->create([
        'family_id' => $family->id,
        'closed_by' => $admin->id,
        'reopened_by' => $admin->id,
    ]);

    expect($bank->family()->first()?->is($family))->toBeTrue()
        ->and($bank->import()->first()?->is($import))->toBeTrue()
        ->and(BankTransaction::query()->forQueue(ReconciliationStatus::Unmatched)->find($bank->id))->not->toBeNull()
        ->and($family->bankTransactions()->find($bank->id))->not->toBeNull()
        ->and($import->family()->first()?->is($family))->toBeTrue()
        ->and($import->uploader()->first()?->is($admin))->toBeTrue()
        ->and($import->transactions()->find($bank->id))->not->toBeNull()
        ->and($group->family()->first()?->is($family))->toBeTrue()
        ->and($group->bankTransaction()->first()?->is($bank))->toBeTrue()
        ->and($group->creator()->first()?->is($admin))->toBeTrue()
        ->and($group->items()->find($item->id))->not->toBeNull()
        ->and($item->group()->first()?->is($group))->toBeTrue()
        ->and($item->paystackTransaction()->first()?->is($providerTransaction))->toBeTrue()
        ->and($item->paymentBatch()->first()?->is($batch))->toBeTrue()
        ->and($link->family()->first()?->is($family))->toBeTrue()
        ->and($link->bankTransaction()->first()?->is($bank))->toBeTrue()
        ->and($link->reconcilable()->first()?->is($batch))->toBeTrue()
        ->and($link->creator()->first()?->is($admin))->toBeTrue()
        ->and($period->family()->first()?->is($family))->toBeTrue()
        ->and($period->closer()->first()?->is($admin))->toBeTrue()
        ->and($period->reopener()->first()?->is($admin))->toBeTrue()
        ->and($period->isLocked())->toBeFalse();

    expect(fn () => $bank->forceFill(['amount' => 99])->save())->toThrow(LogicException::class)
        ->and(fn () => $bank->delete())->toThrow(LogicException::class)
        ->and(fn () => $link->forceFill(['amount' => 99])->save())->toThrow(LogicException::class);
});

it('covers reconciliation domain rejection and closed-period status paths', function () {
    [$family, $admin, $import] = phaseFourCoverageFixture();
    $bank = BankTransaction::factory()->create(['family_id' => $family->id, 'reconciliation_import_id' => $import->id, 'amount' => 500]);
    $batch = PaymentBatch::factory()->create(['family_id' => $family->id, 'total_amount' => 500, 'recorded_by' => $admin->id]);
    $links = app(ReconciliationLinkService::class);

    expect(fn () => $links->link($bank, PaymentBatch::MORPH_TYPE, $batch->id, 0, $admin))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $links->target('unknown', 1, $family->id))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $links->target(PaymentBatch::MORPH_TYPE, 999999, $family->id))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $links->targetAmount($family))->toThrow(InvalidArgumentException::class);

    $links->link($bank, PaymentBatch::MORPH_TYPE, $batch->id, 500, $admin);
    expect(fn () => app(ReconciliationStatusService::class)->update($bank, ReconciliationStatus::Unmatched, $admin, null))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => app(ReconciliationStatusService::class)->update($bank, ReconciliationStatus::Ignored, $admin, 'Ignore linked'))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => app(ReconciliationStatusService::class)->update($bank, ReconciliationStatus::Disputed, $admin, 'Dispute linked'))
        ->toThrow(InvalidArgumentException::class);

    $allocatedTarget = PaymentBatch::factory()->create([
        'family_id' => $family->id,
        'total_amount' => 100,
        'recorded_by' => $admin->id,
        'receipt_number' => 2,
    ]);
    $allocatedBank = BankTransaction::factory()->create([
        'family_id' => $family->id,
        'reconciliation_import_id' => $import->id,
        'amount' => 100,
        'row_fingerprint' => hash('sha256', 'allocated-bank'),
    ]);
    $extraBank = BankTransaction::factory()->create([
        'family_id' => $family->id,
        'reconciliation_import_id' => $import->id,
        'amount' => 100,
        'row_fingerprint' => hash('sha256', 'extra-bank'),
    ]);
    $links->link($allocatedBank, PaymentBatch::MORPH_TYPE, $allocatedTarget->id, 100, $admin);
    expect(fn () => $links->link($extraBank, PaymentBatch::MORPH_TYPE, $allocatedTarget->id, 1, $admin))
        ->toThrow(InvalidArgumentException::class);

    $period = ReconciliationPeriod::factory()->create([
        'family_id' => $family->id,
        'starts_at' => $bank->transacted_at,
        'ends_at' => $bank->transacted_at,
        'status' => ReconciliationPeriodStatus::Closed,
    ]);
    expect(fn () => app(ReconciliationStatusService::class)->update($bank, ReconciliationStatus::Ignored, $admin, 'Closed'))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => $period->delete())->toThrow(LogicException::class)
        ->and(fn () => app(ReconciliationPeriodService::class)->close($period, $admin))->toThrow(InvalidArgumentException::class)
        ->and(fn () => app(ReconciliationPeriodService::class)->reopen($period, $admin, ''))->toThrow(InvalidArgumentException::class)
        ->and(fn () => app(ReconciliationPeriodService::class)->create(
            $family,
            $period->starts_at->toDateString(),
            $period->ends_at->toDateString(),
        ))->toThrow(InvalidArgumentException::class);

    $open = ReconciliationPeriod::factory()->create([
        'family_id' => $family->id,
        'starts_at' => '2026-12-01',
        'ends_at' => '2026-12-31',
    ]);
    expect(fn () => app(ReconciliationPeriodService::class)->reopen($open, $admin, 'Not closed'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => app(ReconciliationPeriodService::class)->create($family, '2026-12-15', '2027-01-15'))->toThrow(InvalidArgumentException::class);
});

it('covers import request and file failure boundaries', function () {
    Storage::fake('local');
    [$family, $admin] = phaseFourCoverageFixture();
    $service = app(BankStatementImportService::class);

    $unreadable = phaseFourUploadedFileMock(function (MockInterface $mock): void {
        $mock->shouldReceive('getRealPath')->once()->andReturn(false);
    });
    expect(fn () => $service->preview($family, $admin, $unreadable))->toThrow(InvalidArgumentException::class);

    $missing = phaseFourUploadedFileMock(function (MockInterface $mock): void {
        $mock->shouldReceive('getRealPath')->once()->andReturn('/definitely/missing-upload.csv');
    });
    expect(fn () => $service->preview($family, $admin, $missing))->toThrow(InvalidArgumentException::class);

    $temporaryPath = tempnam(sys_get_temp_dir(), 'familyfund-statement-');
    if (! is_string($temporaryPath)) {
        throw new RuntimeException('Could not create a temporary statement.');
    }
    file_put_contents($temporaryPath, "Date,Amount,Direction\n2026-11-01,10,credit\n");
    $unstorable = phaseFourUploadedFileMock(function (MockInterface $mock) use ($temporaryPath): void {
        $mock->shouldReceive('getRealPath')->once()->andReturn($temporaryPath);
        $mock->shouldReceive('storeAs')->once()->andReturn(false);
    });
    expect(fn () => $service->preview($family, $admin, $unstorable))->toThrow(RuntimeException::class);
    unlink($temporaryPath);

    $emptyUpload = UploadedFile::fake()->createWithContent('empty.csv', '');
    expect(fn () => $service->preview($family, $admin, $emptyUpload))->toThrow(InvalidArgumentException::class);

    $emptyImport = ReconciliationImport::factory()->create([
        'family_id' => $family->id,
        'uploaded_by' => $admin->id,
        'path' => "reconciliation/{$family->id}/empty.csv",
    ]);
    Storage::disk('local')->put($emptyImport->path, '');
    expect(fn () => $service->import($emptyImport, $admin, ['date' => 'Date']))->toThrow(InvalidArgumentException::class);

    $this->actingAs($admin)->post(route('reconciliation.imports.commit', [
        'current_family' => $family->slug,
        'reconciliation_import' => $emptyImport,
    ]), ['mapping' => 'invalid'])->assertSessionHasErrors('mapping');
});

it('marks failed imports and covers delimiter duplicate and normalization errors', function () {
    Storage::fake('local');
    [$family, $admin] = phaseFourCoverageFixture();
    $service = app(BankStatementImportService::class);
    $semicolon = UploadedFile::fake()->createWithContent('semicolon.csv', "Date;Credit;Debit;Reference\n2026-10-01;1000;;REF-S\n\n2026-10-01;1000;;REF-S\n");
    $preview = $service->preview($family, $admin, $semicolon);
    expect($service->preview($family, $admin, $semicolon)->is($preview))->toBeTrue();
    $result = $service->import($preview, $admin, [
        'date' => 'Date', 'credit' => 'Credit', 'debit' => 'Debit',
        'reference' => 'Reference', 'amount' => null, 'direction' => null,
    ]);
    expect($result)->toBe(['rows' => 2, 'imported' => 2, 'duplicates' => 0]);

    $overlap = UploadedFile::fake()->createWithContent('overlap.csv', "Date;Credit;Debit;Reference\n2026-10-01;1000;;REF-S\n2026-10-02;2000;;REF-T\n");
    $overlapPreview = $service->preview($family, $admin, $overlap);
    expect($service->import($overlapPreview, $admin, [
        'date' => 'Date', 'credit' => 'Credit', 'debit' => 'Debit',
        'reference' => 'Reference', 'amount' => null, 'direction' => null,
    ]))->toBe(['rows' => 2, 'imported' => 1, 'duplicates' => 1]);

    ReconciliationPeriod::factory()->create([
        'family_id' => $family->id,
        'starts_at' => '2026-11-01',
        'ends_at' => '2026-11-30',
        'status' => ReconciliationPeriodStatus::Closed,
    ]);
    $closedPeriodStatement = UploadedFile::fake()->createWithContent(
        'closed-period.csv',
        "Date;Credit;Debit;Reference\n2026-11-15;3000;;REF-CLOSED\n",
    );
    $closedPeriodPreview = $service->preview($family, $admin, $closedPeriodStatement);
    expect(fn () => $service->import($closedPeriodPreview, $admin, [
        'date' => 'Date', 'credit' => 'Credit', 'debit' => 'Debit',
        'reference' => 'Reference', 'amount' => null, 'direction' => null,
    ]))->toThrow(InvalidArgumentException::class)
        ->and(BankTransaction::query()->where('reference', 'REF-CLOSED')->exists())->toBeFalse();

    foreach ([
        ['Date,Credit,Debit', '2026-10-01,10,20'],
        ['Date,Amount,Direction', '2026-10-01,10,sideways'],
        ['Date,Amount,Direction', '2026-10-01,0,credit'],
        ['Date,Amount,Direction', ',10,credit'],
        ['Date,Amount,Direction', '2026-10-01,abc,credit'],
        ['Date,Amount,Direction', '2026-10-01,10.5,credit'],
    ] as $index => [$headers, $row]) {
        $content = $headers."\n".$row."\n";
        $failed = ReconciliationImport::factory()->create([
            'family_id' => $family->id,
            'uploaded_by' => $admin->id,
            'path' => "reconciliation/{$family->id}/invalid-{$index}.csv",
            'headers' => explode(',', $headers),
            'file_fingerprint' => hash('sha256', $content),
        ]);
        Storage::disk('local')->put($failed->path, $content);
        $mapping = str_contains($headers, 'Credit')
            ? ['date' => 'Date', 'credit' => 'Credit', 'debit' => 'Debit']
            : ['date' => 'Date', 'amount' => 'Amount', 'direction' => 'Direction'];
        expect(fn () => $service->import($failed, $admin, $mapping))->toThrow(InvalidArgumentException::class);
        expect($failed->refresh()->status->value)->toBe('failed');
    }

    $alreadyFailed = ReconciliationImport::factory()->create([
        'family_id' => $family->id,
        'uploaded_by' => $admin->id,
        'path' => "reconciliation/{$family->id}/already-failed.csv",
        'status' => ReconciliationImportStatus::Failed,
    ]);
    Storage::disk('local')->put($alreadyFailed->path, "Date,Amount,Direction\n2026-10-01,10,credit\n");
    expect(fn () => $service->import($alreadyFailed, $admin, [
        'date' => 'Date', 'amount' => 'Amount', 'direction' => 'Direction',
    ]))->toThrow(InvalidArgumentException::class, 'Only previewed bank statement imports can be committed.')
        ->and($alreadyFailed->refresh()->status)->toBe(ReconciliationImportStatus::Failed);

    $missing = ReconciliationImport::factory()->create(['family_id' => $family->id, 'path' => 'missing.csv']);
    expect(fn () => $service->import($missing, $admin, ['date' => 'Date']))->toThrow(RuntimeException::class);

    $detect = new ReflectionMethod($service, 'detectDelimiter');
    $readPreview = new ReflectionMethod($service, 'readPreview');
    expect(fn () => $detect->invoke($service, '/definitely/missing.csv'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $readPreview->invoke($service, '/definitely/missing.csv', ','))->toThrow(InvalidArgumentException::class);
});

it('covers provider settlement and workspace edge paths', function () {
    [$family, $admin, $import] = phaseFourCoverageFixture();
    $provider = app(ProviderSettlementService::class);
    expect(fn () => $provider->create($family->id, [], 'NONE', '2026-10-01', $admin))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $provider->create($family->id, [999999], 'MISSING', '2026-10-01', $admin))->toThrow(InvalidArgumentException::class);

    $batch = PaymentBatch::factory()->create([
        'family_id' => $family->id, 'recorded_by' => $admin->id, 'total_amount' => 4000,
    ]);
    $transaction = PaystackTransaction::factory()->create([
        'family_id' => $family->id, 'user_id' => $admin->id, 'payment_batch_id' => $batch->id,
        'amount' => 4000, 'gross_amount_kobo' => 416244,
        'actual_fee_kobo' => 16244, 'settled_amount_kobo' => 400000,
    ]);
    $debit = BankTransaction::factory()->create([
        'family_id' => $family->id, 'reconciliation_import_id' => $import->id,
        'direction' => BankTransactionDirection::Debit,
        'reference' => 'DEBIT-REF',
    ]);
    expect(fn () => $provider->create($family->id, [$transaction->id], 'DEBIT', '2026-10-01', $admin, $debit))->toThrow(InvalidArgumentException::class);
    $koboSettlement = $provider->create($family->id, [$transaction->id], 'KOBO', '2026-10-01', $admin);
    expect($koboSettlement->gross_amount)->toBe(4162)
        ->and($koboSettlement->fee_amount)->toBe(162)
        ->and($koboSettlement->net_amount)->toBe(4000);

    Expense::factory()->recordedBy($admin)->create(['family_id' => $family->id, 'description' => 'Workspace expense']);
    FundAdjustment::factory()->recordedBy($admin)->create(['family_id' => $family->id, 'description' => 'Workspace adjustment']);
    $workspace = app(ReconciliationWorkspaceService::class);
    $data = $workspace->data($family, $admin, ['status' => 'unmatched', 'direction' => 'debit', 'search' => 'missing']);
    expect($data['preview_import'])->toBeNull();

    $label = new ReflectionMethod($workspace, 'targetLabel');
    $numeric = new ReflectionMethod($workspace, 'numericAttribute');
    expect($label->invoke($workspace, new stdClass))->toBe('Ledger entry')
        ->and($numeric->invoke($workspace, new Family, 'missing'))->toBe(0);

    $exactGroup = ProviderSettlementGroup::factory()->create([
        'family_id' => $family->id,
        'reference' => 'PROVIDER-EXACT',
        'net_amount' => 777,
    ]);
    $exactBank = BankTransaction::factory()->create([
        'family_id' => $family->id,
        'reconciliation_import_id' => $import->id,
        'reference' => 'PROVIDER-EXACT',
        'amount' => 777,
    ]);
    $exactCandidates = app(ReconciliationMatchingService::class)->exactCandidates($exactBank);
    expect($exactCandidates)->toHaveCount(1)
        ->and($exactCandidates[0]->is($exactGroup))->toBeTrue()
        ->and(app(ReconciliationMatchingService::class)->exactCandidates($debit))->toBeEmpty();
});
