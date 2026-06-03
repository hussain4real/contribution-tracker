<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Features\AiAssistant;
use App\Http\Requests\FeatureFlagUserRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Pennant\Feature;

class PlatformFeatureFlagController extends Controller
{
    /**
     * All class-based features managed via this UI.
     *
     * @var array<string, array{class: class-string, name: string, description: string}>
     */
    private const array FEATURES = [
        'ai-assistant' => [
            'class' => AiAssistant::class,
            'name' => 'AI Assistant',
            'description' => 'Enables the AI-powered assistant for family insights and chat.',
        ],
    ];

    public function index(): Response
    {
        $features = collect(self::FEATURES)->map(function (array $meta, string $key) {
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

            // Collect user IDs with explicit 'true' activation records
            $scopes = DB::table('features')
                ->where('name', $featureClass)
                ->where('scope', '!=', '')
                ->where('value', 'true')
                ->pluck('scope')
                ->all();
            $activatedUserIds = [];

            foreach ($scopes as $scope) {
                if (is_string($scope)) {
                    $activatedUserIds[] = (int) str($scope)->afterLast('|')->toString();
                }
            }

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

        $users = User::query()
            ->select('id', 'name', 'email')
            ->orderBy('name')
            ->get()
            ->map(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ]);

        return Inertia::render('Platform/FeatureFlags', [
            'features' => $features,
            'users' => $users,
        ]);
    }

    public function activateForEveryone(string $feature): RedirectResponse
    {
        $meta = $this->resolveFeature($feature);

        // Delete cached 'false' rows so existing users get re-resolved against the global flag
        DB::table('features')
            ->where('name', $meta['class'])
            ->where('scope', '!=', '')
            ->where('value', 'false')
            ->delete();

        // Upsert the global activation record (empty scope) — preserves per-user records
        DB::table('features')->updateOrInsert(
            ['name' => $meta['class'], 'scope' => ''],
            ['value' => 'true', 'created_at' => now(), 'updated_at' => now()]
        );

        // Flush the in-memory cache so the new global state takes effect
        Feature::flushCache();

        return redirect()->back()->with('success', "\"{$meta['name']}\" has been activated for everyone.");
    }

    public function deactivateForEveryone(string $feature): RedirectResponse
    {
        $meta = $this->resolveFeature($feature);

        // Remove ALL feature records — global sentinel and per-user records
        DB::table('features')
            ->where('name', $meta['class'])
            ->delete();

        // Flush the in-memory cache so the deactivation takes effect
        Feature::flushCache();

        return redirect()->back()->with('success', "\"{$meta['name']}\" has been deactivated for everyone.");
    }

    public function activateForUser(FeatureFlagUserRequest $request, string $feature): RedirectResponse
    {
        $meta = $this->resolveFeature($feature);

        $user = User::query()
            ->whereKey($request->integer('user_id'))
            ->firstOrFail();

        Feature::for($user)->activate($meta['class']);

        return redirect()->back()->with('success', "\"{$meta['name']}\" has been activated for {$user->name}.");
    }

    public function deactivateForUser(FeatureFlagUserRequest $request, string $feature): RedirectResponse
    {
        $meta = $this->resolveFeature($feature);

        $user = User::query()
            ->whereKey($request->integer('user_id'))
            ->firstOrFail();

        Feature::for($user)->deactivate($meta['class']);

        return redirect()->back()->with('success', "\"{$meta['name']}\" has been deactivated for {$user->name}.");
    }

    /**
     * Resolve a feature key to its metadata.
     *
     * @return array{class: class-string, name: string, description: string}
     */
    private function resolveFeature(string $feature): array
    {
        abort_unless(array_key_exists($feature, self::FEATURES), 404);

        return self::FEATURES[$feature];
    }
}
