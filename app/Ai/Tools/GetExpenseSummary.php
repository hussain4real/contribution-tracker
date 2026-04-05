<?php

namespace App\Ai\Tools;

use App\Models\Expense;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class GetExpenseSummary implements Tool
{
    public function __construct(private User $user) {}

    /**
     * Get the description of the tool's purpose.
     */
    public function description(): Stringable|string
    {
        return 'Retrieves expense summary data for the family. Can filter by date range (start_date and end_date in Y-m-d format). Returns total expenses, expense count, and individual expense entries.';
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): Stringable|string
    {
        $query = Expense::query()
            ->where('family_id', $this->user->family_id)
            ->with('recorder')
            ->latestFirst();

        $startDate = $request['start_date'] ?? now()->startOfMonth()->toDateString();
        $endDate = $request['end_date'] ?? now()->endOfMonth()->toDateString();

        $query->spentBetween($startDate, $endDate);

        $expenses = $query->limit(50)->get();

        $totalAmount = $expenses->sum('amount');

        $expenseList = $expenses->map(fn (Expense $expense) => [
            'description' => $expense->description,
            'amount' => $expense->amount,
            'spent_at' => $expense->spent_at->format('Y-m-d'),
            'recorded_by' => $expense->recorder?->name ?? 'Unknown',
        ])->toArray();

        return json_encode([
            'period' => "{$startDate} to {$endDate}",
            'total_amount' => $totalAmount,
            'expense_count' => $expenses->count(),
            'expenses' => $expenseList,
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * Get the tool's schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'start_date' => $schema->string(),
            'end_date' => $schema->string(),
        ];
    }
}
