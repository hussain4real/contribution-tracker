<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ReconciliationPeriodStatus;
use App\Models\Family;
use App\Models\ReconciliationPeriod;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

class ReconciliationPeriodGuard
{
    public function ensureLedgerDateIsWritable(int $familyId, DateTimeInterface|string $date): void
    {
        $ledgerDate = Carbon::parse($date)->toDateString();
        Family::query()->lockForUpdate()->findOrFail($familyId);
        $isClosed = ReconciliationPeriod::query()
            ->where('family_id', $familyId)
            ->where('status', ReconciliationPeriodStatus::Closed)
            ->whereDate('starts_at', '<=', $ledgerDate)
            ->whereDate('ends_at', '>=', $ledgerDate)
            ->exists();

        if ($isClosed) {
            throw new InvalidArgumentException('This ledger date belongs to a closed reconciliation period. Reopen the period before changing its ledger.');
        }
    }
}
