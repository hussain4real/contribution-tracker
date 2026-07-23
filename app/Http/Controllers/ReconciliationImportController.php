<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\CommitReconciliationImportRequest;
use App\Http\Requests\StoreReconciliationImportRequest;
use App\Models\Family;
use App\Models\ReconciliationImport;
use App\Services\BankStatementImportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\UploadedFile;

class ReconciliationImportController extends Controller
{
    public function __construct(private readonly BankStatementImportService $imports) {}

    public function store(StoreReconciliationImportRequest $request): RedirectResponse
    {
        $user = $this->user($request);
        $family = $user->currentFamily ?? $user->family;
        $statement = $request->file('statement');
        abort_unless($family instanceof Family && $statement instanceof UploadedFile, 403);
        $import = $this->imports->preview($family, $user, $statement);

        return redirect()->route('reconciliation.index', ['preview_import' => $import->id])
            ->with('success', 'Statement uploaded. Confirm the column mapping to import it.');
    }

    public function commit(CommitReconciliationImportRequest $request, ReconciliationImport $reconciliationImport): RedirectResponse
    {
        $result = $this->imports->import($reconciliationImport, $this->user($request), $request->mapping());

        return redirect()->route('reconciliation.index')
            ->with('success', "Imported {$result['imported']} rows; {$result['duplicates']} duplicates were safely skipped.");
    }
}
