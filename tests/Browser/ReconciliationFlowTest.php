<?php

declare(strict_types=1);

use App\Enums\PaymentSource;
use App\Enums\ReconciliationStatus;
use App\Models\BankTransaction;
use App\Models\PaymentBatch;
use App\Models\ReconciliationImport;
use App\Models\ReconciliationPeriod;
use Illuminate\Support\Facades\Storage;

describe('Reconciliation workflow (Browser)', function () {
    beforeEach(function () {
        Storage::fake('local');
        $this->family = createBrowserFamily(['name' => 'Browser Reconciliation Family']);
        $this->admin = createBrowserAdmin($this->family, [
            'email' => 'reconciliation-admin@example.com',
        ]);
        PaymentBatch::factory()->create([
            'family_id' => $this->family->id,
            'total_amount' => 5000,
            'paid_at' => '2026-07-01',
            'reference' => 'PSK-BROWSER-EXACT',
            'source' => PaymentSource::Paystack,
            'recorded_by' => $this->admin->id,
        ]);
        $statement = implode("\n", [
            'Date,Amount,Direction,Reference,Description,Account',
            '2026-07-01,5000,Credit,PSK-BROWSER-EXACT,Browser bank credit,Main',
        ]);
        $import = ReconciliationImport::factory()->create([
            'family_id' => $this->family->id,
            'uploaded_by' => $this->admin->id,
            'path' => "reconciliation/{$this->family->id}/browser-statement.csv",
            'file_fingerprint' => hash('sha256', $statement),
            'headers' => ['Date', 'Amount', 'Direction', 'Reference', 'Description', 'Account'],
            'preview_rows' => [[
                'Date' => '2026-07-01',
                'Amount' => '5000',
                'Direction' => 'Credit',
                'Reference' => 'PSK-BROWSER-EXACT',
                'Description' => 'Browser bank credit',
                'Account' => 'Main',
            ]],
        ]);
        Storage::disk('local')->put($import->path, $statement);
    });

    it('previews maps imports and auto-matches a statement through the UI', function () {
        $page = loginBrowserAs($this->admin);
        $import = ReconciliationImport::query()->where('family_id', $this->family->id)->firstOrFail();

        $page->navigate(route('reconciliation.index', ['preview_import' => $import->id]))
            ->assertSee('Bank reconciliation')
            ->assertSee('Import a bank statement')
            ->assertSee('Map columns for')
            ->assertSee('Browser bank credit')
            ->select('select[name="mapping[date]"]', 'Date')
            ->select('select[name="mapping[amount]"]', 'Amount')
            ->select('select[name="mapping[direction]"]', 'Direction')
            ->select('select[name="mapping[reference]"]', 'Reference')
            ->select('select[name="mapping[description]"]', 'Description')
            ->select('select[name="mapping[source_account]"]', 'Account')
            ->click('Import and auto-match exact references')
            ->assertSee('Browser bank credit')
            ->assertSee('Matched')
            ->assertNoJavaScriptErrors();

        $transaction = BankTransaction::query()->where('family_id', $this->family->id)->firstOrFail();

        expect($transaction->status)->toBe(ReconciliationStatus::Matched)
            ->and($transaction->links()->count())->toBe(1);
    });

    it('opens a reconciliation period from the workspace', function () {
        $page = loginBrowserAs($this->admin);

        $page->navigate(route('reconciliation.index'))
            ->resize(390, 844)
            ->assertSee('Bank reconciliation')
            ->fill('starts_at', '2026-07-01')
            ->fill('ends_at', '2026-07-31')
            ->click('Open period')
            ->assertSee('2026-07-01')
            ->assertSee('2026-07-31')
            ->assertNoJavaScriptErrors();

        $viewport = $page->script('() => ({ documentWidth: document.documentElement.scrollWidth, viewportWidth: document.documentElement.clientWidth })');

        if (! is_array($viewport)) {
            throw new RuntimeException('Expected reconciliation viewport measurements.');
        }

        $documentWidth = $viewport['documentWidth'] ?? null;
        $viewportWidth = $viewport['viewportWidth'] ?? null;

        if (! is_int($documentWidth) || ! is_int($viewportWidth)) {
            throw new RuntimeException('Expected reconciliation viewport widths to be integers.');
        }

        expect(ReconciliationPeriod::query()
            ->where('family_id', $this->family->id)
            ->whereDate('starts_at', '2026-07-01')
            ->whereDate('ends_at', '2026-07-31')
            ->exists())->toBeTrue()
            ->and($documentWidth)->toBeLessThanOrEqual($viewportWidth + 1);
    });
});
