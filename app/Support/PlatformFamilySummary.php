<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Contribution;
use App\Models\Family;
use App\Models\Payment;

final class PlatformFamilySummary
{
    /**
     * @return array{total_contributions: int, total_collected: int, total_expected: int, collection_rate: float|int, active_members: int, archived_members: int}
     */
    public static function for(Family $family): array
    {
        $memberIds = $family->members()->pluck('users.id');

        $totalContributions = Contribution::query()
            ->whereIn('user_id', $memberIds)
            ->count();

        $totalCollected = (int) Payment::query()
            ->whereIn(
                'contribution_id',
                Contribution::query()->whereIn('user_id', $memberIds)->select('id'),
            )
            ->sum('amount');

        $totalExpected = (int) Contribution::query()
            ->whereIn('user_id', $memberIds)
            ->sum('expected_amount');

        $activeMembers = $family->members()
            ->whereNull('archived_at')
            ->count();

        $archivedMembers = $family->members()
            ->whereNotNull('archived_at')
            ->count();

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
