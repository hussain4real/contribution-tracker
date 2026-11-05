<?php

declare(strict_types=1);

namespace App\Ai\Tools;

use App\Models\Contribution;
use App\Models\Family;
use App\Models\User;
use App\Services\FamilyFinancialVisibility;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

class GetMyOutstanding implements Tool
{
    public function __construct(private User $user) {}

    public function description(): string
    {
        return 'Retrieves only the current user\'s unpaid contributions across all years by default. Includes overdue and upcoming amounts, effective payments, reporting period and source links. Optional year and month filters must be explicitly requested by the user.';
    }

    public function handle(Request $request): string
    {
        $family = app(FamilyFinancialVisibility::class)->activeFamily($this->user);
        if (! $family instanceof Family) {
            return json_encode(['error' => 'No active family membership.'], JSON_THROW_ON_ERROR);
        }
        $validated = $request->validate(['year' => ['required_with:month', 'integer', 'between:2020,2030'], 'month' => ['sometimes', 'integer', 'between:1,12']]);
        $input = new \Illuminate\Http\Request($validated);
        $year = $input->filled('year') ? $input->integer('year') : null;
        $month = $input->filled('month') ? $input->integer('month') : null;
        $contributions = Contribution::query()->where('family_id', $family->id)->where('user_id', $this->user->id)
            ->when($year !== null, fn ($query) => $query->where('year', $year))
            ->when($month !== null, fn ($query) => $query->where('month', $month))
            ->withSum('payments as paid_amount', 'amount')->orderBy('due_date')->orderBy('id')->get();
        $unpaid = $contributions->filter(fn (Contribution $contribution): bool => $this->outstanding($contribution) > 0);
        $outstanding = (int) $unpaid->sum(fn (Contribution $contribution): int => $this->outstanding($contribution));
        $overdue = (int) $unpaid->filter(fn (Contribution $contribution): bool => $contribution->due_date->lt(today()))->sum(fn (Contribution $contribution): int => $this->outstanding($contribution));

        return json_encode([
            'as_of' => now()->toIso8601String(), 'currency' => $family->currency, 'scope' => 'personal',
            'period' => $year === null ? 'All years' : ($month === null ? "Year {$year}" : sprintf('%04d-%02d', $year, $month)),
            'outstanding' => $outstanding, 'overdue' => $overdue, 'not_yet_overdue' => $outstanding - $overdue,
            'unpaid_count' => $unpaid->count(), 'entries_truncated' => $unpaid->count() > 50,
            'source_url' => route('contributions.my', ['current_family' => $family->slug]),
            'contributions' => $unpaid->take(50)->map(fn (Contribution $contribution): array => [
                'period' => $contribution->period_label, 'due_date' => $contribution->due_date->toDateString(),
                'expected' => $contribution->expected_amount, 'paid' => (int) ($contribution->paid_amount ?? 0), 'outstanding' => $this->outstanding($contribution),
                'source_url' => route('contributions.show', ['current_family' => $family->slug, 'contribution' => $contribution->id]),
            ])->values()->all(),
        ], JSON_THROW_ON_ERROR);
    }

    private function outstanding(Contribution $contribution): int
    {
        return max(0, $contribution->expected_amount - (int) ($contribution->paid_amount ?? 0));
    }

    public function schema(JsonSchema $schema): array
    {
        return ['year' => $schema->integer()->min(2020)->max(2030), 'month' => $schema->integer()->min(1)->max(12)];
    }
}
