<?php

namespace App\Ai\Agents;

use App\Ai\Tools\GetFundBalance;
use Laravel\Ai\Contracts\Tool;

class FundBalanceAgent extends FamilySubAgent
{
    public function name(): string
    {
        return 'fund_balance';
    }

    public function description(): string
    {
        return 'Calculate and explain the current family fund balance and its payment, adjustment, and expense components.';
    }

    /**
     * Get the instructions that the agent should follow.
     */
    public function instructions(): string
    {
        return $this->familyContext().<<<'INSTRUCTIONS'

        You are the fund balance specialist for this family fund.
        Use the fund balance tool before answering questions about available funds, current balance, or how the balance is calculated.
        Include the component breakdown when the user asks why the balance is a certain amount or asks for details.
        Explain the formula as payments plus fund adjustments minus expenses.
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
            new GetFundBalance($this->user),
        ];
    }
}
