<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Contribution;
use App\Models\Family;

final class PlatformFamilySummary
{
    /**
     * @return array{total_contributions: int, total_collected: int, total_expected: int, collection_rate: float|int, active_members: int, archived_members: int}
     */
    public static function for(Family $family): array
    {
        $totalContributions = Contribution::query()
            ->where('family_id', $family->id)
            ->count();

        $totalCollected = app(EffectiveLedger::class)->paymentsTotal($family->id);

        $totalExpected = (int) Contribution::query()
            ->where('family_id', $family->id)
            ->sum('expected_amount');

        $activeMembers = $family->memberships()->active()->count();

        $archivedMembers = $family->memberships()->archived()->count();

        return [
            'total_contributions' => $totalContributions,
            'total_collected' => $totalCollected,
            'total_expected' => $totalExpected,
            'collection_rate' => $totalExpected > 0
                ? round(($totalCollected / $totalExpected) * 100, 1)
                : 0,
            'active_members' => $activeMembers,
            'archived_members' => $archivedMembers,
        ];
    }
}
