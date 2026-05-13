<?php

namespace App\Ai\Agents;

use App\Ai\Tools\RecordPayment;
use Laravel\Ai\Contracts\Tool;

class PaymentRecordingAgent extends FamilySubAgent
{
    public function name(): string
    {
        return 'payment_recording';
    }

    public function description(): string
    {
        return 'Preview and record family member payments using the required confirm-first workflow.';
    }

    /**
     * Get the instructions that the agent should follow.
     */
    public function instructions(): string
    {
        return $this->familyContext()."\n\n".$this->confirmFirstInstructions().<<<'INSTRUCTIONS'

        You are the payment recording specialist for this family fund.
        Use the payment recording tool for member payment requests, including advance payments and allocation to unpaid contributions.
        Require a member name and amount before previewing or recording a payment.
        If multiple members match, ask for a more specific member name.
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
            new RecordPayment($this->user),
        ];
    }
}
