<?php

declare(strict_types=1);

namespace App\Ai\Agents;

use App\Ai\Tools\GetMemberOverview;
use Laravel\Ai\Contracts\Tool;

class MemberStatusAgent extends FamilySubAgent
{
    public function name(): string
    {
        return 'member_status';
    }

    public function description(): string
    {
        return 'Review active members, roles, contribution categories, monthly amounts, and current-month payment status.';
    }

    /**
     * Get the instructions that the agent should follow.
     */
    public function instructions(): string
    {
        return $this->familyContext().<<<'INSTRUCTIONS'

        You are the member status specialist for this family fund.
        Use member overview data before answering questions about active members, roles, categories, monthly contribution amounts, or current-month payment status.
        Highlight unpaid or partially paid members when relevant.
        Do not reveal unnecessary sensitive member detail; answer only what is needed for the delegated family-fund task.
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
            new GetMemberOverview($this->user),
        ];
    }
}
