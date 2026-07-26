<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\FundAdjustment;
use App\Services\ReconciliationPeriodGuard;
use App\Support\AuditEventRecorder;

class FundAdjustmentObserver
{
    public function __construct(
        private AuditEventRecorder $audit,
        private ReconciliationPeriodGuard $periodGuard,
    ) {}

    public function creating(FundAdjustment $adjustment): void
    {
        $this->periodGuard->ensureDateIsWritable($adjustment->family_id, $adjustment->recorded_at);
    }

    public function created(FundAdjustment $adjustment): void
    {
        $this->audit->record(
            $adjustment,
            'fund_adjustment.posted',
            $adjustment->family_id,
            $adjustment->recorded_by,
            after: [
                'amount' => $adjustment->amount,
                'description' => $adjustment->description,
                'recorded_at' => $adjustment->recorded_at->toDateString(),
            ],
        );
    }
}
