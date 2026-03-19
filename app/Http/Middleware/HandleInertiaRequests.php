<?php

namespace App\Http\Middleware;

use Illuminate\Foundation\Inspiring;
use Illuminate\Http\Request;
use Inertia\Middleware;

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
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
        ];
    }
}
