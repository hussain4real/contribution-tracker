<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\ArchiveFamily;
use App\Actions\RestoreFamily;
use App\Http\Requests\ArchiveFamilyRequest;
use App\Models\Family;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FamilyArchiveController extends Controller
{
    public function show(Request $request): Response
    {
        $family = $this->family($request);

        return Inertia::render('Family/Archived', [
            'family' => [
                'name' => $family->name,
                'archived_at' => $family->archived_at?->toIso8601String(),
                'purge_after' => $family->purge_after?->toIso8601String(),
                'archive_reason' => $family->archive_reason,
                'legal_hold' => $family->isOnLegalHold(),
            ],
        ]);
    }

    public function store(ArchiveFamilyRequest $request, ArchiveFamily $archiveFamily): RedirectResponse
    {
        $family = $this->family($request);
        $archiveFamily->handle($family, $this->user($request), $request->reason());

        return redirect()->route('family.archive.show', ['current_family' => $family->slug])
            ->with('success', 'Family archived. Restore and export remain available for 30 days.');
    }

    public function restore(Request $request, RestoreFamily $restoreFamily): RedirectResponse
    {
        $user = $this->user($request);
        abort_unless($user->isAdmin(), 403);
        $family = $restoreFamily->handle($this->family($request));

        return redirect()->route('dashboard', ['current_family' => $family->slug])
            ->with('success', 'Family restored.');
    }

    public function export(Request $request): StreamedResponse
    {
        $user = $this->user($request);
        abort_unless($user->isAdmin(), 403);
        $family = $this->family($request);
        $family->load([
            'categories',
            'memberships.user',
            'contributions.allPayments',
            'paymentBatches.allocations',
            'expenses',
            'fundAdjustments',
            'reportSchedules.deliveries',
            'reconciliationImports.transactions.links',
            'reconciliationPeriods',
            'providerSettlementGroups.items',
        ]);

        return response()->streamDownload(function () use ($family): void {
            echo json_encode([
                'exported_at' => now()->toIso8601String(),
                'family' => $family->toArray(),
            ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        }, "{$family->slug}-archive-export.json", ['Content-Type' => 'application/json']);
    }

    private function family(Request $request): Family
    {
        $family = $this->user($request)->currentFamily ?? $this->user($request)->family;
        abort_unless($family instanceof Family, 403);

        return $family;
    }
}
