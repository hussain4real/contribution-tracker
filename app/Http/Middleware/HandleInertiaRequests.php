<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Features\AiAssistant;
use App\Models\Family;
use App\Models\FamilyCategory;
use App\Models\FamilyMembership;
use App\Models\PlatformPlan;
use App\Models\User;
use App\Services\GitHubReleaseService;
use App\Support\PlatformPlanCatalog;
use Illuminate\Foundation\Inspiring;
use Illuminate\Http\Request;
use Inertia\Middleware;
use Laravel\Pennant\Feature;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $quote = Inspiring::quotes()->random();
        $quote = is_string($quote) ? $quote : '';
        [$message, $author] = array_pad(explode('-', $quote, 2), 2, '');

        $user = $request->user();

        if ($user instanceof User) {
            $user->loadMissing([
                'currentFamily:id,name,slug,currency,due_day,bank_name,account_name,account_number',
                'family:id,name,slug,currency,due_day,bank_name,account_name,account_number',
                'familyCategory:id,name,monthly_amount',
            ]);
        }

        $boundFamily = app()->bound('current-family') ? app('current-family') : null;
        $currentFamily = $boundFamily instanceof Family
            ? $boundFamily
            : ($user instanceof User ? ($user->currentFamily ?? $user->family) : null);
        $currentMembership = $user instanceof User ? $user->currentFamilyMembership() : null;
        $activeRole = $currentMembership instanceof FamilyMembership
            ? $currentMembership->role
            : ($user instanceof User ? $user->role : null);
        $activeCategoryValue = $currentMembership instanceof FamilyMembership
            ? $currentMembership->category?->value
            : ($user instanceof User ? $user->category?->value : null);
        $activeCategoryLabel = null;

        if ($currentMembership instanceof FamilyMembership) {
            $activeCategoryLabel = $currentMembership->category?->label();

            if (
                $currentMembership->family_category_id !== null
                && $currentMembership->familyCategory instanceof FamilyCategory
            ) {
                $activeCategoryLabel = $currentMembership->familyCategory->name;
            }
        } elseif ($user instanceof User) {
            $activeCategoryLabel = $user->category?->label();

            if (
                $user->family_category_id !== null
                && $user->familyCategory instanceof FamilyCategory
            ) {
                $activeCategoryLabel = $user->familyCategory->name;
            }
        }

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'quote' => ['message' => trim($message), 'author' => trim($author)],
            'auth' => [
                'user' => $user instanceof User ? [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'email_verified_at' => $user->email_verified_at,
                    'role' => $activeRole?->value,
                    'role_label' => $activeRole?->label(),
                    'category' => $activeCategoryValue,
                    'category_label' => $activeCategoryLabel,
                    'family_id' => $user->family_id,
                    'current_family_id' => $user->current_family_id,
                    'is_super_admin' => $user->is_super_admin,
                    'whatsapp_phone' => $user->whatsapp_phone,
                    'whatsapp_verified_at' => $user->whatsapp_verified_at,
                ] : null,
                'can' => $user ? [
                    'add_members' => $activeRole?->canAddMembers() ?? false,
                    'manage_members' => $activeRole?->canManageMembers() ?? false,
                    'record_payments' => $activeRole?->canRecordPayments() ?? false,
                    'generate_reports' => $activeRole?->canGenerateReports() ?? false,
                ] : null,
            ],
            'family' => $currentFamily ? [
                'id' => $currentFamily->id,
                'name' => $currentFamily->name,
                'slug' => $currentFamily->slug,
                'currency' => $currentFamily->currency,
                'due_day' => $currentFamily->due_day,
                'bank_name' => $currentFamily->bank_name,
                'account_name' => $currentFamily->account_name,
                'account_number' => $currentFamily->account_number,
            ] : null,
            'families' => $user ? fn () => $user->toUserFamilies() : null,
            'flash' => [
                'success' => fn () => $request->hasSession() ? $request->session()->get('success') : null,
                'error' => fn () => $request->hasSession() ? $request->session()->get('error') : null,
                'warning' => fn () => $request->hasSession() ? $request->session()->get('warning') : null,
            ],
            'subscription' => $currentFamily && $user instanceof User ? fn () => $this->subscriptionData($user) : null,
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
            'impersonating' => $request->hasSession() && $request->session()->has('impersonating_from'),
            'featureFlags' => $user ? [
                'ai_assistant' => fn () => Feature::for($user)->active(AiAssistant::class),
            ] : null,
            'changelogUpdate' => $user ? fn () => app(GitHubReleaseService::class)->updateDataFor($user) : null,
            'webPush' => $user ? fn () => $this->webPushData($user) : null,
            'notifications' => $user ? [
                'unread_count' => fn () => $user->unreadNotifications()->count(),
                'recent' => fn () => $user->unreadNotifications()->latest()->limit(10)->get()->map(fn ($n) => [
                    'id' => $n->id,
                    'type' => $n->type,
                    'data' => $n->data,
                    'read_at' => $n->read_at,
                    'created_at' => $n->created_at?->diffForHumans(),
                ]),
            ] : null,
        ];
    }

    /**
     * Get the subscription/plan data for a user's family.
     *
     * @return array<string, mixed>
     */
    private function subscriptionData(User $user): array
    {
        $family = $user->currentFamily ?? $user->family;

        if (! $family instanceof Family) {
            return [
                'plan_name' => null,
                'member_count' => 0,
                'max_members' => null,
                'can_add_members' => false,
                'features' => [],
            ];
        }

        $family->loadCount('members');
        $family->loadMissing('platformPlan');

        $plan = $family->platformPlan ?? $this->defaultFreePlan();
        $memberCount = $family->members_count;

        $features = $plan ? $plan->features : [];

        return [
            'plan_name' => $plan?->name,
            'member_count' => $memberCount,
            'max_members' => $plan && ! $plan->hasUnlimitedMembers() ? $plan->max_members : null,
            'can_add_members' => ! $plan || $plan->hasUnlimitedMembers() || $memberCount < $plan->max_members,
            'features' => $features,
        ];
    }

    private function defaultFreePlan(): ?PlatformPlan
    {
        return PlatformPlan::query()
            ->where('slug', PlatformPlanCatalog::Free)
            ->where('is_active', true)
            ->first();
    }

    /**
     * Get safe web push data for the authenticated user's browser.
     *
     * @return array{enabled: bool, publicKey: string|null, subscribed: bool}
     */
    private function webPushData(User $user): array
    {
        $publicKey = config('webpush.vapid.public_key');

        if (! is_string($publicKey) || blank($publicKey)) {
            return [
                'enabled' => false,
                'publicKey' => null,
                'subscribed' => false,
            ];
        }

        return [
            'enabled' => true,
            'publicKey' => $publicKey,
            'subscribed' => $user->hasWebPushSubscription(),
        ];
    }
}
