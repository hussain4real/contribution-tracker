<?php

declare(strict_types=1);

namespace App\Ai\Agents;

use App\Ai\Tools\GetExpenseSummary;
use Laravel\Ai\Contracts\Tool;

class ExpenseAnalysisAgent extends FamilySubAgent
{
    public function name(): string
    {
        return 'expense_analysis';
    }

    public function description(): string
    {
        return 'Analyze family expenses, spending totals, date-range summaries, and recent expense entries.';
    }

    /**
     * Get the instructions that the agent should follow.
     */
    public function instructions(): string
    {
        return $this->familyContext().<<<'INSTRUCTIONS'

        You are the expense analysis specialist for this family fund.
        Use expense summary data before answering questions about spending totals, expense counts, date ranges, individual expense entries, or who recorded expenses.
        When the delegated task does not specify dates, use the current month from the context above.
        Summarize the most important expenses first and say when there are no matching expenses.
        INSTRUCTIONS;
    }

    /**
     * Get the tools available to the agent.
     *
     * @return array<int, Tool>
     */
    public function tools(): iterable
    {
        return [
            new GetExpenseSummary($this->user),
        ];
    }
}
