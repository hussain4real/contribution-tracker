<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\AiActionKind;
use App\Http\Requests\StoreFundAdjustmentRequest;
use App\Models\Family;
use App\Models\FundAdjustment;
use App\Services\AiActionDrafts;
use App\Support\CurrencyFormatter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class FundAdjustmentController extends Controller
{
    /**
     * Display a listing of all fund adjustments with a form to create new ones.
     */
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', FundAdjustment::class);

        $user = $this->authUser();
        $family = $user->currentFamily ?? $user->family;

        abort_unless($family !== null, 403);
        $draft = app(AiActionDrafts::class)->read($request, $user, $family, AiActionKind::Adjustment);

        $adjustments = FundAdjustment::query()
            ->where('family_id', $family->id)
            ->with(['recorder', 'reversal'])
            ->latestFirst()
            ->latest('id')
            ->paginate(20)
            ->through(fn (FundAdjustment $adjustment) => [
                'id' => $adjustment->id,
                'amount' => $adjustment->amount,
                'description' => $adjustment->description,
                'recorded_at' => $adjustment->recorded_at->toDateString(),
                'recorded_by' => $adjustment->recorder?->name,
                'created_at' => $adjustment->created_at?->toDateString(),
                'is_reversed' => $adjustment->isReversed(),
                'reversal_reason' => $adjustment->reversal?->reason,
                'can_reverse' => $user->can('delete', $adjustment) && ! $adjustment->isReversed(),
            ]);

        return Inertia::render('FundAdjustments/Index', [
            'adjustments' => $adjustments,
            'can_create' => $user->canRecordPayments(),
            'aiDraft' => $draft['values'] ?? null,
            'idempotencyKey' => $draft['key'] ?? (string) Str::uuid(),
            'idempotencyScope' => "adjustment-{$family->id}-{$user->id}".($draft ? "-{$draft['key']}" : ''),
        ]);
    }

    /**
     * Store a newly created fund adjustment.
     */
    public function store(StoreFundAdjustmentRequest $request): RedirectResponse
    {
        $user = $this->user($request);
        $family = $user->currentFamily ?? $user->family;

        abort_unless($family !== null, 403);
        $amount = $request->integer('amount');

        $wasReversed = DB::transaction(function () use ($amount, $family, $request, $user): bool {
            Family::query()->lockForUpdate()->findOrFail($family->id);
            $key = $request->string('idempotency_key')->toString();
            $existing = FundAdjustment::query()->where('family_id', $family->id)->where('idempotency_key', $key)->first();
            if ($existing instanceof FundAdjustment) {
                if ($existing->recorded_by !== $user->id || $existing->amount !== $amount
                    || $existing->description !== $request->string('description')->toString()
                    || $existing->recorded_at->toDateString() !== $request->date('recorded_at')?->toDateString()) {
                    throw ValidationException::withMessages(['idempotency_key' => 'This request was already used for a different adjustment. Check the adjustment list before starting a new adjustment.']);
                }

                return $existing->isReversed();
            }

            FundAdjustment::create([
                'family_id' => $family->id,
                'amount' => $amount,
                'description' => $request->string('description')->toString(),
                'recorded_at' => $request->string('recorded_at')->toString(),
                'recorded_by' => $user->id,
                'idempotency_key' => $key,
            ]);

            return false;
        }, attempts: 3);

        $formattedAmount = CurrencyFormatter::format($amount, $family->currency);

        return redirect()->route('fund-adjustments.index')
            ->with('success', $wasReversed
                ? 'This fund adjustment was already recorded and later reversed.'
                : "Fund adjustment of {$formattedAmount} recorded successfully.");
    }
}
