<?php

declare(strict_types=1);

namespace App\Support;

use App\Features\AiAssistant;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class PlatformFeatureRegistry
{
    /**
     * @var array<string, array{class: class-string, name: string, description: string}>
     */
    private const array FEATURES = [
        'ai-assistant' => [
            'class' => AiAssistant::class,
            'name' => 'AI Assistant',
            'description' => 'Enables the AI-powered assistant for family insights and chat.',
        ],
    ];

    /**
     * @return array<int, array{key: string, name: string, description: string, status: string, activated_count: int, total_resolved: int, activated_user_ids: list<int>}>
     */
    public static function all(): array
    {
        return collect(self::FEATURES)->map(function (array $meta, string $key): array {
            $featureClass = $meta['class'];

            $row = DB::table('features')
                ->where('name', $featureClass)
                ->selectRaw("
                    SUM(CASE WHEN scope != '' AND value = 'true' THEN 1 ELSE 0 END) AS activated_count,
                    SUM(CASE WHEN scope != '' THEN 1 ELSE 0 END)                     AS total_resolved,
                    MAX(CASE WHEN scope = ''  AND value = 'true' THEN 1 ELSE 0 END)  AS is_global
                ")
                ->first();

            $activatedCountValue = $row->activated_count ?? null;
            $totalResolvedValue = $row->total_resolved ?? null;
            $activatedCount = is_numeric($activatedCountValue) ? (int) $activatedCountValue : 0;
            $totalResolved = is_numeric($totalResolvedValue) ? (int) $totalResolvedValue : 0;
            $isGlobal = (bool) ($row->is_global ?? false);

            $status = match (true) {
                $isGlobal => 'active',
                $activatedCount > 0 => 'partial',
                default => 'inactive',
            };

            $activatedUserIds = array_values(DB::table('features')
                ->where('name', $featureClass)
                ->where('scope', '!=', '')
                ->where('value', 'true')
                ->pluck('scope')
                ->filter(fn (mixed $scope): bool => is_string($scope))
                ->map(fn (string $scope): int => (int) str($scope)->afterLast('|')->toString())
                ->values()
                ->all());

            return [
                'key' => $key,
                'name' => $meta['name'],
                'description' => $meta['description'],
                'status' => $status,
                'activated_count' => $activatedCount,
                'total_resolved' => $totalResolved,
                'activated_user_ids' => $activatedUserIds,
            ];
        })->values()->all();
    }

    /**
     * @return array<string, array{class: class-string, name: string, description: string}>
     */
    public static function options(): array
    {
        return self::FEATURES;
    }

    /**
     * @return array{class: class-string, name: string, description: string}
     */
    public static function resolve(string $feature): array
    {
        abort_unless(array_key_exists($feature, self::FEATURES), 404);

        return self::FEATURES[$feature];
    }

    /**
     * @return array<int, string>
     */
    public static function userOptions(): array
    {
        return User::query()
            ->orderBy('name')
            ->get(['id', 'name', 'email'])
            ->mapWithKeys(fn (User $user): array => [
                $user->id => "{$user->name} <{$user->email}>",
            ])
            ->all();
    }
}
