<?php

namespace App\Http\Middleware;

use App\Features\AiAssistant;
use App\Models\User;
use App\Services\GitHubReleaseService;
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
        [$message, $author] = str(Inspiring::quotes()->random())->explode('-');

        $user = $request->user();

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'quote' => ['message' => trim($message), 'author' => trim($author)],
            'auth' => [
                'user' => $user ? [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'email_verified_at' => $user->email_verified_at,
                    'role' => $user->role->value,
                    'role_label' => $user->role->label(),
                    'category' => $user->category?->value,
                    'category_label' => $user->category?->label(),
                    'family_id' => $user->family_id,
                    'is_super_admin' => $user->is_super_admin,
                    'whatsapp_phone' => $user->whatsapp_phone,
                    'whatsapp_verified_at' => $user->whatsapp_verified_at,
                ] : null,
                'can' => $user ? [
                    'manage_members' => $user->role->canManageMembers(),
                    'record_payments' => $user->role->canRecordPayments(),
                    'generate_reports' => $user->role->canGenerateReports(),
                ] : null,
            ],
            'family' => $user?->family ? [
                'id' => $user->family->id,
                'name' => $user->family->name,
                'currency' => $user->family->currency,
                'due_day' => $user->family->due_day,
                'bank_name' => $user->family->bank_name,
                'account_name' => $user->family->account_name,
                'account_number' => $user->family->account_number,
            ] : null,
            'flash' => [
                'success' => fn () => $request->hasSession() ? $request->session()->get('success') : null,
                'error' => fn () => $request->hasSession() ? $request->session()->get('error') : null,
                'warning' => fn () => $request->hasSession() ? $request->session()->get('warning') : null,
            ],
            'subscription' => $user?->family ? fn () => $this->subscriptionData($user) : null,
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
                    'created_at' => $n->created_at->diffForHumans(),
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
        $family = $user->family;
        $family->loadCount('members');
        $family->loadMissing('platformPlan');

        $plan = $family->platformPlan;
        $memberCount = $family->members_count;

        return [
            'plan_name' => $plan?->name,
            'member_count' => $memberCount,
            'max_members' => $plan && ! $plan->hasUnlimitedMembers() ? $plan->max_members : null,
            'can_add_members' => ! $plan || $plan->hasUnlimitedMembers() || $memberCount < $plan->max_members,
            'features' => $plan?->features ?? [],
        ];
    }

    /**
     * Get safe web push data for the authenticated user's browser.
     *
     * @return array{enabled: bool, publicKey: string|null, subscribed: bool}
     */
    private function webPushData(User $user): array
    {
        $publicKey = config('webpush.vapid.public_key');

        if (blank($publicKey)) {
            return [
                'enabled' => false,
                'publicKey' => null,
                'subscribed' => false,
            ];
        }

        return [
            'enabled' => true,
            'publicKey' => $publicKey,
            'subscribed' => $user->pushSubscriptions()->exists(),
        ];
    }
}
