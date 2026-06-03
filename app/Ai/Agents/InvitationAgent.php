<?php

declare(strict_types=1);

namespace App\Ai\Agents;

use App\Ai\Tools\SendInvitation;
use Laravel\Ai\Contracts\Tool;

class InvitationAgent extends FamilySubAgent
{
    public function name(): string
    {
        return 'invitation_management';
    }

    public function description(): string
    {
        return 'Preview and send family invitations by email using the required confirm-first workflow.';
    }

    /**
     * Get the instructions that the agent should follow.
     */
    public function instructions(): string
    {
        return $this->familyContext()."\n\n".$this->confirmFirstInstructions().<<<'INSTRUCTIONS'

        You are the invitation management specialist for this family fund.
        Use the invitation tool when an admin wants to invite someone by email.
        Require a valid email address and one role: admin, financial_secretary, or member.
        Explain duplicate-member or pending-invitation problems clearly.
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
            new SendInvitation($this->user),
        ];
    }
}
