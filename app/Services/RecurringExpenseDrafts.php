<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ExpenseRequest;
use App\Models\ExpenseTemplate;
use App\Models\Family;
use App\Support\AuditEventRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RecurringExpenseDrafts
{
    public function __construct(private ExpenseGovernance $governance, private AuditEventRecorder $audit) {}

    public function nextDate(int $day, CarbonImmutable $today): CarbonImmutable
    {
        $date = $this->dateInMonth($day, $today);

        return $date->lt($today->startOfDay()) ? $this->dateInMonth($day, $today->startOfMonth()->addMonth()) : $date;
    }

    public function generate(ExpenseTemplate $template, CarbonImmutable $today): bool
    {
        return DB::transaction(function () use ($template, $today): bool {
            $family = Family::query()->lockForUpdate()->find($template->family_id);
            if (! $family instanceof Family || $family->isArchived() || $family->isSuspended() || ! $this->governance->supportsApprovals($family)) {
                return false;
            }

            $locked = ExpenseTemplate::query()->where('family_id', $family->id)->lockForUpdate()->find($template->id);
            $scheduledDate = $locked?->next_run_on;
            if ($scheduledDate === null || $scheduledDate > $today->toDateString()) {
                return false;
            }

            $date = CarbonImmutable::parse($scheduledDate, 'UTC');
            $draft = ExpenseRequest::query()->firstOrCreate([
                'family_id' => $family->id,
                'expense_template_id' => $locked->id,
                'template_month' => $date->startOfMonth()->toDateString(),
            ], [
                'expense_category_id' => $locked->expense_category_id,
                'description' => $locked->description,
                'amount' => $locked->amount,
                'spent_at' => $date->toDateString(),
                'status' => 'draft',
                'required_approvals' => 0,
                'idempotency_key' => (string) Str::uuid(),
            ]);
            if ($draft->wasRecentlyCreated) {
                $this->audit->record($draft, 'expense_request.drafted', $family->id);
            }
            $locked->update(['next_run_on' => $this->dateInMonth($locked->day_of_month, $date->startOfMonth()->addMonth())->toDateString()]);

            return $draft->wasRecentlyCreated;
        });
    }

    private function dateInMonth(int $day, CarbonImmutable $month): CarbonImmutable
    {
        return $month->startOfMonth()->day(min($day, $month->daysInMonth));
    }
}
