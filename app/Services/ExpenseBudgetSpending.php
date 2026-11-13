<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Expense;
use App\Models\ExpenseApproval;
use App\Models\ExpenseBudget;
use App\Models\ExpenseCategory;
use App\Models\ExpenseRequest;
use App\Models\Family;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/** @phpstan-type BudgetRow array{category_id: int|null, category: string, budget: int|null, actual: int, committed: int, remaining: int|null, actual_url: string, committed_url: string} */
class ExpenseBudgetSpending
{
    /** @var array<string, list<BudgetRow>> */
    private array $summaries = [];

    public function __construct(private ExpenseGovernance $governance) {}

    /** @return Builder<Expense> */
    public function actualQuery(Family $family, string $month): Builder
    {
        $start = Carbon::parse($month.'-01');

        return Expense::query()->where('family_id', $family->id)->effective()
            ->where('spent_at', '>=', $start->toDateString())
            ->where('spent_at', '<', $start->addMonth()->toDateString());
    }

    /** @return Builder<ExpenseRequest> */
    public function committedQuery(Family $family, string $month): Builder
    {
        $start = Carbon::parse($month.'-01');
        $requests = (new ExpenseRequest)->getTable();
        $count = ExpenseApproval::query()->selectRaw('count(*)')
            ->whereColumn('expense_request_id', $requests.'.id')
            ->whereColumn('family_id', $requests.'.family_id')
            ->where(function (Builder $query) use ($requests): void {
                $query->whereColumn('user_id', '!=', $requests.'.requested_by')
                    ->orWhere(fn (Builder $missingRequester) => $missingRequester->whereNull($requests.'.requested_by')->whereNotNull('user_id'));
            })->where('decision', 'approved');
        $query = ExpenseRequest::query()->where('family_id', $family->id)
            ->whereIn('status', ['submitted', 'approved'])->whereDoesntHave('expense')
            ->whereDoesntHave('approvals', fn (Builder $approvals) => $approvals->getQuery()->where('decision', 'rejected'))
            ->where('spent_at', '>=', $start->toDateString())->where('spent_at', '<', $start->addMonth()->toDateString())
            ->where('required_approvals', '<=', $count);
        $required = $this->governance->requiredApprovals($family, PHP_INT_MAX);
        if ($required > 0) {
            $threshold = $family->expense_approval_settings['threshold'] ?? 1;
            $query->where(function (Builder $query) use ($threshold, $count, $required): void {
                $query->where('amount', '<', $threshold);
                $query->orWhereExists((clone $count)->havingRaw('COUNT(*) >= ?', [$required])->toBase());
            });
        }

        return $query;
    }

    /** @return list<BudgetRow> */
    public function summary(Family $family, string $month): array
    {
        $key = $family->id.':'.$month;
        if (isset($this->summaries[$key])) {
            return $this->summaries[$key];
        }
        $actual = $this->actualQuery($family, $month)->selectRaw('expense_category_id, SUM(amount) AS total')
            ->groupBy('expense_category_id')->toBase()->pluck('total', 'expense_category_id');
        $committed = $this->committedQuery($family, $month)->selectRaw('expense_category_id, SUM(amount) AS total')
            ->groupBy('expense_category_id')->toBase()->pluck('total', 'expense_category_id');
        $budgets = ExpenseBudget::query()->where('family_id', $family->id)->where('month', $month.'-01')->get()->keyBy('expense_category_id');
        $categories = ExpenseCategory::query()->where('family_id', $family->id)->orderBy('name')->get()
            ->map(fn (ExpenseCategory $category): array => ['id' => $category->id, 'name' => $category->name])->all();
        $categories[] = ['id' => null, 'name' => 'Uncategorized'];

        return $this->summaries[$key] = array_values(array_map(function (array $category) use ($family, $month, $actual, $committed, $budgets): array {
            $id = $category['id'];
            $limit = $id === null ? null : $budgets->get($id)?->amount;
            $spentValue = $actual->get($id ?? '', 0);
            $spent = is_numeric($spentValue) ? (int) $spentValue : 0;
            $committedValue = $committed->get($id ?? '', 0);
            $reserved = is_numeric($committedValue) ? (int) $committedValue : 0;
            $parameters = ['current_family' => $family->slug, 'month' => $month, 'category' => $id];

            return [
                'category_id' => $id, 'category' => $category['name'], 'budget' => $limit,
                'actual' => $spent, 'committed' => $reserved,
                'remaining' => $limit === null ? null : $limit - $spent - $reserved,
                'actual_url' => route('expense-budgets.details', [...$parameters, 'kind' => 'actual']),
                'committed_url' => route('expense-budgets.details', [...$parameters, 'kind' => 'committed']),
            ];
        }, $categories));
    }

    /** @return array{budget: int, projected: int, over_by: int, month: string, url: string}|null */
    public function advisory(Family $family, ?int $categoryId, string $month, int $amount, bool $alreadyCommitted = false): ?array
    {
        if ($categoryId === null) {
            return null;
        }

        foreach ($this->summary($family, $month) as $row) {
            if ($row['category_id'] !== $categoryId || $row['budget'] === null) {
                continue;
            }
            $projected = $row['actual'] + $row['committed'] + ($alreadyCommitted ? 0 : $amount);

            return ['budget' => $row['budget'], 'projected' => $projected,
                'over_by' => max(0, $projected - $row['budget']), 'month' => $month,
                'url' => route('expense-budgets.index', ['current_family' => $family->slug, 'month' => $month])];
        }

        return null;
    }
}
