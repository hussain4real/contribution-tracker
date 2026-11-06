<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\GoalMeasure;
use App\Http\Requests\ArchiveFamilyGoalRequest;
use App\Http\Requests\StoreFamilyGoalRequest;
use App\Http\Requests\UpdateFamilyGoalRequest;
use App\Models\Family;
use App\Models\FamilyGoal;
use App\Services\FamilyFinancialVisibility;
use App\Services\FamilyGoalProgress;
use App\Support\AuditEventRecorder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class FamilyGoalController extends Controller
{
    private function currentFamily(?FamilyGoal $goal = null): Family
    {
        $user = $this->authUser();
        $family = $user->currentFamily ?? $user->family;
        abort_unless($family instanceof Family && app(FamilyFinancialVisibility::class)->totals($user, $family), 403);
        abort_if($goal !== null && $goal->family_id !== $family->id, 403);

        return $family;
    }

    public function index(Request $request, FamilyGoalProgress $progress): Response
    {
        $request->validate(['archived' => ['sometimes', 'boolean']]);
        $family = $this->currentFamily();
        $canTrace = app(FamilyFinancialVisibility::class)->transactions($this->authUser(), $family);

        return Inertia::render('Family/Goals', [
            'goals' => $progress->query($family)->when(! $request->boolean('archived'), fn ($query) => $query->whereNull('archived_at'))
                ->latest('id')->paginate(12)->withQueryString()->through(fn (FamilyGoal $goal): array => $progress->present($goal, $family, $canTrace)),
            'can_manage' => $this->authUser()->isAdmin(),
            'show_archived' => $request->boolean('archived'),
            'measures' => array_map(fn (GoalMeasure $measure): array => ['value' => $measure->value, 'label' => $measure->label()], GoalMeasure::cases()),
            'idempotencyKey' => (string) Str::uuid(),
            'today' => now()->toDateString(),
            'defaultDeadline' => now()->endOfYear()->toDateString(),
        ]);
    }

    public function store(StoreFamilyGoalRequest $request): RedirectResponse
    {
        $family = $this->currentFamily();
        DB::transaction(function () use ($family, $request): void {
            Family::query()->lockForUpdate()->findOrFail($family->id);
            $attributes = $request->attributesForGoal();
            $existing = FamilyGoal::query()->where('family_id', $family->id)->where('idempotency_key', $request->string('idempotency_key')->toString())->first();
            if ($existing instanceof FamilyGoal) {
                if ($existing->created_by !== $this->authUser()->id || $existing->name !== $request->string('name')->toString()
                    || $existing->measure->value !== $request->string('measure')->toString()
                    || $existing->target_amount !== $request->integer('target_amount')
                    || $existing->starts_on->toDateString() !== $request->string('starts_on')->toString()
                    || $existing->deadline->toDateString() !== $request->string('deadline')->toString()) {
                    throw ValidationException::withMessages(['idempotency_key' => 'This goal request was already used. Check your goals before starting a new one.']);
                }

                return;
            }
            $goal = FamilyGoal::query()->create([...$attributes, 'family_id' => $family->id, 'created_by' => $this->authUser()->id, 'idempotency_key' => $request->string('idempotency_key')->toString()]);
            app(AuditEventRecorder::class)->record($goal, 'family.goal_created', $family->id, after: $attributes);
        });

        return back()->with('success', 'Visual goal created. Progress follows the existing family ledger.');
    }

    public function update(UpdateFamilyGoalRequest $request, FamilyGoal $familyGoal): RedirectResponse
    {
        $family = $this->currentFamily($familyGoal);
        DB::transaction(function () use ($request, $familyGoal, $family): void {
            $locked = FamilyGoal::query()->lockForUpdate()->findOrFail($familyGoal->id);
            $before = $locked->only(['name', 'measure', 'target_amount', 'starts_on', 'deadline']);
            $locked->update($request->attributesForGoal());
            app(AuditEventRecorder::class)->record($locked, 'family.goal_updated', $family->id, before: $before, after: $request->attributesForGoal());
        });

        return back()->with('success', 'Visual goal updated.');
    }

    public function archive(ArchiveFamilyGoalRequest $request, FamilyGoal $familyGoal): RedirectResponse
    {
        $family = $this->currentFamily($familyGoal);
        DB::transaction(function () use ($request, $familyGoal, $family): void {
            $locked = FamilyGoal::query()->lockForUpdate()->findOrFail($familyGoal->id);
            $archived = $request->boolean('archived');
            if (($locked->archived_at !== null) === $archived) {
                return;
            }
            $locked->update(['archived_at' => $archived ? now() : null]);
            app(AuditEventRecorder::class)->record($locked, $archived ? 'family.goal_archived' : 'family.goal_restored', $family->id);
        });

        return back()->with('success', 'Goal visibility updated.');
    }
}
