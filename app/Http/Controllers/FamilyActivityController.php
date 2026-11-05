<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\FamilyActivityRequest;
use App\Models\AuditEvent;
use App\Models\Family;
use App\Services\FamilyFinancialOverview;
use App\Support\EffectiveLedger;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class FamilyActivityController extends Controller
{
    public function index(FamilyActivityRequest $request, EffectiveLedger $ledger, FamilyFinancialOverview $overview): Response
    {
        $user = $this->user($request);
        $family = $user->currentFamily ?? $user->family;
        abort_unless($family instanceof Family, 403);
        $period = $request->string('period')->toString() ?: now()->format('Y-m');
        $start = Carbon::parse($period.'-01')->startOfDay();
        $end = $start->copy()->addMonth()->toDateString();
        $type = $request->string('type')->toString() ?: 'all';
        $search = $request->string('search')->toString();
        $payments = $ledger->paymentsForFamily($family->id)
            ->where('paid_at', '>=', $start->toDateString())->where('paid_at', '<', $end)
            ->selectRaw("id, 'payment' as kind, paid_at as date, amount, 'Contribution payment' as description");
        $expenses = $ledger->expensesForFamily($family->id)
            ->where('spent_at', '>=', $start->toDateString())->where('spent_at', '<', $end)
            ->selectRaw("id, 'expense' as kind, spent_at as date, amount, description");
        $adjustments = $ledger->adjustmentsForFamily($family->id)
            ->where('recorded_at', '>=', $start->toDateString())->where('recorded_at', '<', $end)
            ->selectRaw("id, 'adjustment' as kind, recorded_at as date, amount, description");
        $records = DB::query()->fromSub($payments->toBase()->unionAll($expenses->toBase())->unionAll($adjustments->toBase()), 'entries')
            ->when($type !== 'all', fn ($query) => $query->where('kind', $type))
            ->when($search !== '', fn ($query) => $query->where('description', 'like', '%'.$search.'%'))
            ->orderByDesc('date')->orderBy('kind')->orderByDesc('id')
            ->paginate(20)->withQueryString();
        $events = AuditEvent::query()->where('family_id', $family->id)
            ->where('created_at', '>=', $start->toDateString())->where('created_at', '<', $end)
            ->when($search !== '', fn ($query) => $query->where('action', 'like', '%'.$search.'%'))
            ->with('actor:id,name')->latest('id')->paginate(20, ['*'], 'events_page')
            ->withQueryString()->through(fn (AuditEvent $event): array => [
                'id' => $event->id,
                'action' => str($event->action)->replace('.', ' ')->headline()->toString(),
                'actor' => $event->actor->name ?? 'System',
                'date' => $event->created_at->toIso8601String(),
                'reason' => in_array($event->action, ['financial.reversed', 'financial.corrected'], true)
                    && is_string($event->after['reason'] ?? null) ? $event->after['reason'] : null,
            ]);

        return Inertia::render('Family/Activity', [
            'overview' => $overview->forMonth($family->id, $start),
            'entries' => $records,
            'events' => $events,
            'filters' => ['period' => $start->format('Y-m'), 'type' => $type, 'search' => $search],
        ]);
    }
}
