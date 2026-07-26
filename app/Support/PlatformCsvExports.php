<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Family;
use App\Models\User;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class PlatformCsvExports
{
    public static function families(): StreamedResponse
    {
        $families = Family::query()
            ->withCount('members')
            ->with('owner')
            ->latest()
            ->get();

        return response()->streamDownload(function () use ($families): void {
            $handle = fopen('php://output', 'w');

            // @codeCoverageIgnoreStart
            if ($handle === false) {
                return;
            }
            // @codeCoverageIgnoreEnd

            fputcsv($handle, ['ID', 'Name', 'Slug', 'Currency', 'Due Day', 'Owner', 'Members', 'Suspended', 'Created']);

            foreach ($families as $family) {
                fputcsv($handle, [
                    $family->id,
                    $family->name,
                    $family->slug,
                    $family->currency,
                    $family->due_day,
                    $family->owner instanceof User ? $family->owner->name : '',
                    $family->members_count,
                    $family->suspended_at ? 'Yes' : 'No',
                    $family->created_at?->toDateString(),
                ]);
            }

            fclose($handle);
        }, 'families-'.now()->format('Y-m-d').'.csv', [
            'Content-Type' => 'text/csv',
        ]);
    }

    public static function users(): StreamedResponse
    {
        $users = User::query()
            ->with(['family', 'familyCategory:id,name,monthly_amount'])
            ->latest()
            ->get();

        return response()->streamDownload(function () use ($users): void {
            $handle = fopen('php://output', 'w');

            // @codeCoverageIgnoreStart
            if ($handle === false) {
                return;
            }
            // @codeCoverageIgnoreEnd

            fputcsv($handle, ['ID', 'Name', 'Email', 'Family', 'Role', 'Category', 'Status', 'Joined']);

            foreach ($users as $user) {
                fputcsv($handle, [
                    $user->id,
                    $user->name,
                    $user->email,
                    $user->family instanceof Family ? $user->family->name : '',
                    $user->role->label(),
                    $user->familyCategory->name ?? $user->category?->label() ?? '',
                    $user->archived_at === null ? 'Active' : 'Archived',
                    $user->created_at?->toDateString(),
                ]);
            }

            fclose($handle);
        }, 'users-'.now()->format('Y-m-d').'.csv', [
            'Content-Type' => 'text/csv',
        ]);
    }
}
