<?php

declare(strict_types=1);

namespace App\Ai\Agents;

use App\Ai\Tools\RecordFundAdjustment;
use Laravel\Ai\Contracts\Tool;

class FundAdjustmentRecordingAgent extends FamilySubAgent
{
    public function name(): string
    {
        return 'fund_adjustment_recording';
    }

    public function description(): string
    {
        return 'Preview and record fund adjustments such as donations, corrections, and interest using the confirm-first workflow.';
    }

    /**
     * Get the instructions that the agent should follow.
     */
    public function instructions(): string
    {
        return $this->familyContext()."\n\n".$this->confirmFirstInstructions().<<<'INSTRUCTIONS'

        You are the fund adjustment recording specialist for this family fund.
        Use the fund adjustment tool for donations, corrections, interest earned, refunds, or other balance adjustments that are not member contribution payments.
        Require an amount and description before previewing or recording an adjustment.
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
            new RecordFundAdjustment($this->user),
        ];
    }
}
