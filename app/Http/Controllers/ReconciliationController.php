<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Family;
use App\Models\ReconciliationImport;
use App\Services\ReconciliationWorkspaceService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ReconciliationController extends Controller
{
    public function __construct(private readonly ReconciliationWorkspaceService $workspace) {}

    public function index(Request $request): Response
    {
        $this->authorize('reconcile-family-funds');
        $user = $this->user($request);
        $family = $user->currentFamily ?? $user->family;
        abort_unless($family instanceof Family, 403);
        $previewId = $request->integer('preview_import');
        $preview = $previewId > 0 ? ReconciliationImport::query()->find($previewId) : null;

        return Inertia::render('Reconciliation/Index', $this->workspace->data($family, $user, [
            'status' => $request->string('status')->toString() ?: null,
            'direction' => $request->string('direction')->toString() ?: null,
            'search' => $request->string('search')->toString() ?: null,
        ], $preview));
    }
}
