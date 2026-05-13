<?php

namespace App\Ai\Agents;

use App\Ai\Tools\RecordExpense;
use Laravel\Ai\Contracts\Tool;

class ExpenseRecordingAgent extends FamilySubAgent
{
    public function name(): string
    {
        return 'expense_recording';
    }

    public function description(): string
    {
        return 'Preview and record family expenses using the required confirm-first workflow.';
    }

    /**
     * Get the instructions that the agent should follow.
     */
    public function instructions(): string
    {
        return $this->familyContext()."\n\n".$this->confirmFirstInstructions().<<<'INSTRUCTIONS'

        You are the expense recording specialist for this family fund.
        Use the expense recording tool for requests to save family spending.
        Require an amount and description before previewing or recording an expense.
        If no date is provided, use today's date from the context above.
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
            new RecordExpense($this->user),
        ];
    }
}
