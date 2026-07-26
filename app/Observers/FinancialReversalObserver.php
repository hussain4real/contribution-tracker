<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\FinancialReversal;
use App\Support\AuditEventRecorder;

class FinancialReversalObserver
{
    public function __construct(private AuditEventRecorder $audit) {}

    public function created(FinancialReversal $reversal): void
    {
        $this->audit->record(
            $reversal,
            $reversal->replacement_id === null ? 'financial.reversed' : 'financial.corrected',
            $reversal->family_id,
            $reversal->reversed_by,
            after: [
                'reversible_type' => $reversal->reversible_type,
                'reversible_id' => $reversal->reversible_id,
                'replacement_type' => $reversal->replacement_type,
                'replacement_id' => $reversal->replacement_id,
                'reason' => $reversal->reason,
            ],
        );
    }
}
