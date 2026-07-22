<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\FinancialReversal;
use App\Models\FundAdjustment;
use App\Models\User;
use App\Services\ReconciliationPeriodGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class ReverseFundAdjustment
{
    public function __construct(private readonly ReconciliationPeriodGuard $periodGuard) {}

    public function handle(
        FundAdjustment $adjustment,
        User $actor,
        string $reason,
        ?FundAdjustment $replacement = null,
    ): FinancialReversal {
        return DB::transaction(function () use ($adjustment, $actor, $reason, $replacement): FinancialReversal {
            $lockedAdjustment = FundAdjustment::query()->lockForUpdate()->findOrFail($adjustment->id);
            $existing = $lockedAdjustment->reversal()->first();

            if ($existing instanceof FinancialReversal) {
                return $existing;
            }

            if (trim($reason) === '') {
                throw new InvalidArgumentException('A reversal reason is required.');
            }

            $this->periodGuard->ensureLedgerDateIsWritable($lockedAdjustment->family_id, $lockedAdjustment->recorded_at);

            if ($replacement instanceof FundAdjustment && $replacement->family_id !== $lockedAdjustment->family_id) {
                throw new InvalidArgumentException('A replacement adjustment must belong to the same family.');
            }

            return FinancialReversal::query()->create([
                'family_id' => $lockedAdjustment->family_id,
                'reversible_type' => FundAdjustment::MORPH_TYPE,
                'reversible_id' => $lockedAdjustment->id,
                'replacement_type' => $replacement?->getMorphClass(),
                'replacement_id' => $replacement?->id,
                'reason' => trim($reason),
                'reversed_by' => $actor->id,
                'request_id' => $this->requestId(),
            ]);
        }, 3);
    }

    private function requestId(): string
    {
        $requestId = app()->bound('request-id') ? app('request-id') : null;

        return is_string($requestId) && Str::isUuid($requestId)
            ? $requestId
            : (string) Str::uuid();
    }
}
