<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\PaymentBatch;
use App\Support\AuditEventRecorder;

class PaymentBatchObserver
{
    public function __construct(private AuditEventRecorder $audit) {}

    public function created(PaymentBatch $batch): void
    {
        $this->audit->record(
            $batch,
            'payment.posted',
            $batch->family_id,
            $batch->recorded_by,
            after: [
                'family_membership_id' => $batch->family_membership_id,
                'total_amount' => $batch->total_amount,
                'paid_at' => $batch->paid_at->toDateString(),
                'method' => $batch->method->value,
                'source' => $batch->source->value,
                'reference' => $batch->reference,
                'receipt_number' => $batch->receipt_number,
            ],
        );
    }
}
