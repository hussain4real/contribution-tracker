<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Features\AiAssistant;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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

            $activatedCount = DB::table('features')
                ->where('name', $featureClass)
                ->where('scope', '!=', '')
                ->where('value', 'true')
                ->count();

            $totalResolved = DB::table('features')
                ->where('name', $featureClass)
                ->where('scope', '!=', '')
                ->count();

            $isGlobal = DB::table('features')
                ->where('name', $featureClass)
                ->where('scope', '')
                ->where('value', 'true')
                ->exists();

            $status = match (true) {
                $isGlobal => 'active',
                $activatedCount > 0 => 'partial',
                default => 'inactive',
            };

            return [
                'key' => $key,
                'name' => $meta['name'],
                'description' => $meta['description'],
                'status' => $status,
                'activated_count' => $activatedCount,
                'total_resolved' => $totalResolved,
            ];
        })->values()->all();

        $users = User::query()
            ->select('id', 'name', 'email', 'is_super_admin')
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

        // Purge per-user cached values so they re-resolve via the global flag
        Feature::purge($meta['class']);

        // Insert a global activation record (empty scope) — after purge
        DB::table('features')->insert([
            'name' => $meta['class'],
            'scope' => '',
            'value' => 'true',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return redirect()->back()->with('success', "\"{$meta['name']}\" has been activated for everyone.");
    }

    public function deactivateForEveryone(string $feature): RedirectResponse
    {
        $meta = $this->resolveFeature($feature);

        // Remove the global activation record
        DB::table('features')
            ->where('name', $meta['class'])
            ->where('scope', '')
            ->delete();

        // Purge per-user cached values so they re-resolve as inactive
        Feature::purge($meta['class']);

        return redirect()->back()->with('success', "\"{$meta['name']}\" has been deactivated for everyone.");
    }

    public function activateForUser(Request $request, string $feature): RedirectResponse
    {
        $meta = $this->resolveFeature($feature);

        $validated = $request->validate([
            'user_id' => ['required', 'exists:users,id'],
        ]);

        $user = User::findOrFail($validated['user_id']);

        Feature::for($user)->activate($meta['class']);

        return redirect()->back()->with('success', "\"{$meta['name']}\" has been activated for {$user->name}.");
    }

    public function deactivateForUser(Request $request, string $feature): RedirectResponse
    {
        $meta = $this->resolveFeature($feature);

        $validated = $request->validate([
            'user_id' => ['required', 'exists:users,id'],
        ]);

        $user = User::findOrFail($validated['user_id']);

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
