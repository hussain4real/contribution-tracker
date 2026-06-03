<?php

declare(strict_types=1);

namespace App\Ai\Agents;

use App\Ai\Tools\GetContributionSummary;
use Laravel\Ai\Contracts\Tool;

class ContributionAnalysisAgent extends FamilySubAgent
{
    public function name(): string
    {
        return 'contribution_analysis';
    }

    public function description(): string
    {
        return 'Analyze family contribution expectations, collections, outstanding balances, collection rates, and member payment gaps.';
    }

    /**
     * Get the instructions that the agent should follow.
     */
    public function instructions(): string
    {
        return $this->familyContext().<<<'INSTRUCTIONS'

        You are the contribution analysis specialist for this family fund.
        Use contribution summary data before answering questions about expected contributions, paid amounts, outstanding balances, collection rates, overdue members, or contribution trends.
        When the delegated task does not specify a period, use the current year and current month from the context above.
        Keep answers concise, include relevant totals, and mention data gaps instead of guessing.
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
            new GetContributionSummary($this->user),
        ];
    }
}
