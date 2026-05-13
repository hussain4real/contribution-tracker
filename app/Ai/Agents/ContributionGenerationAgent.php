<?php

namespace App\Ai\Agents;

use App\Ai\Tools\GenerateContributions;
use Laravel\Ai\Contracts\Tool;

class ContributionGenerationAgent extends FamilySubAgent
{
    public function name(): string
    {
        return 'contribution_generation';
    }

    public function description(): string
    {
        return 'Preview and generate monthly contribution records for active family members using the confirm-first workflow.';
    }

    /**
     * Get the instructions that the agent should follow.
     */
    public function instructions(): string
    {
        return $this->familyContext()."\n\n".$this->confirmFirstInstructions().<<<'INSTRUCTIONS'

        You are the contribution generation specialist for this family fund.
        Use the contribution generation tool when an admin wants to create expected contribution records for a month.
        If the delegated task does not specify a year or month, use the current year and current month from the context above.
        Make it clear that this creates expected contribution entries, not payments.
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
            new GenerateContributions($this->user),
        ];
    }
}
