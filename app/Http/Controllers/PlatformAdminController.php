<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Contribution;
use App\Models\Expense;
use App\Models\Family;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Password;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PlatformAdminController extends Controller
{
    public function index(): Response
    {
        $totalFamilies = Family::count();
        $totalUsers = User::count();
        $activeUsers = User::active()->count();
        $archivedUsers = User::archived()->count();
        $totalPayments = (int) Payment::sum('amount');
        $totalExpenses = (int) Expense::sum('amount');
        $totalContributions = Contribution::count();
        $newFamiliesThisMonth = Family::query()
            ->where('created_at', '>=', now()->startOfMonth())
            ->count();

        $recentFamilies = Family::query()
            ->withCount('members')
            ->latest()
            ->limit(10)
            ->get()
            ->map(fn (Family $family) => [
                'id' => $family->id,
                'name' => $family->name,
                'slug' => $family->slug,
                'currency' => $family->currency,
                'members_count' => $family->members_count,
                'created_at' => $family->created_at->toDateString(),
            ]);

        $recentPayments = Payment::query()
            ->with(['contribution.user', 'recorder'])
            ->latest()
            ->limit(5)
            ->get()
            ->map(fn (Payment $payment) => [
                'id' => $payment->id,
                'amount' => $payment->amount,
                'member_name' => $payment->contribution?->user?->name,
                'recorded_by' => $payment->recorder?->name,
                'created_at' => $payment->created_at->toDateString(),
            ]);

        return Inertia::render('Platform/Dashboard', [
            'stats' => [
                'total_families' => $totalFamilies,
                'total_users' => $totalUsers,
                'active_users' => $activeUsers,
                'archived_users' => $archivedUsers,
                'total_payments' => $totalPayments,
                'total_expenses' => $totalExpenses,
                'total_contributions' => $totalContributions,
                'new_families_this_month' => $newFamiliesThisMonth,
            ],
            'recent_families' => $recentFamilies,
            'recent_payments' => $recentPayments,
        ]);
    }

    public function families(): Response
    {
        $families = Family::query()
            ->withCount('members')
            ->with('owner')
            ->latest()
            ->paginate(20)
            ->through(fn (Family $family) => [
                'id' => $family->id,
                'name' => $family->name,
                'slug' => $family->slug,
                'currency' => $family->currency,
                'due_day' => $family->due_day,
                'members_count' => $family->members_count,
                'owner_name' => $family->owner?->name,
                'is_suspended' => $family->isSuspended(),
                'created_at' => $family->created_at->toDateString(),
            ]);

        return Inertia::render('Platform/Families', [
            'families' => $families,
        ]);
    }

    public function users(): Response
    {
        $users = User::query()
            ->with('family')
            ->latest()
            ->paginate(20)
            ->through(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role->label(),
                'category' => $user->category?->label(),
                'family_name' => $user->family?->name,
                'family_id' => $user->family_id,
                'is_active' => $user->archived_at === null,
                'created_at' => $user->created_at->toDateString(),
            ]);

        return Inertia::render('Platform/Users', [
            'users' => $users,
        ]);
    }

    public function showFamily(Family $family): Response
    {
        $family->load(['owner', 'categories', 'members' => fn ($q) => $q->orderBy('name')]);

        $totalContributions = Contribution::query()
            ->whereIn('user_id', $family->members->pluck('id'))
            ->count();

        $totalCollected = (int) Payment::query()
            ->whereHas('contribution', fn ($q) => $q->whereIn('user_id', $family->members->pluck('id')))
            ->sum('amount');

        $totalExpected = (int) Contribution::query()
            ->whereIn('user_id', $family->members->pluck('id'))
            ->sum('expected_amount');

        $activeMembers = $family->members->filter(fn ($m) => $m->archived_at === null)->count();
        $archivedMembers = $family->members->filter(fn ($m) => $m->archived_at !== null)->count();

        return Inertia::render('Platform/FamilyDetail', [
            'family' => [
                'id' => $family->id,
                'name' => $family->name,
                'slug' => $family->slug,
                'currency' => $family->currency,
                'due_day' => $family->due_day,
                'owner' => $family->owner ? [
                    'id' => $family->owner->id,
                    'name' => $family->owner->name,
                    'email' => $family->owner->email,
                ] : null,
                'categories' => $family->categories->map(fn ($c) => [
                    'id' => $c->id,
                    'name' => $c->name,
                    'monthly_amount' => $c->monthly_amount,
                ]),
                'members' => $family->members->map(fn ($m) => [
                    'id' => $m->id,
                    'name' => $m->name,
                    'email' => $m->email,
                    'role' => $m->role->label(),
                    'is_active' => $m->archived_at === null,
                ]),
                'financial_summary' => [
                    'total_contributions' => $totalContributions,
                    'total_collected' => $totalCollected,
                    'total_expected' => $totalExpected,
                    'collection_rate' => $totalExpected > 0
                        ? round(($totalCollected / $totalExpected) * 100, 1)
                        : 0,
                    'active_members' => $activeMembers,
                    'archived_members' => $archivedMembers,
                ],
                'created_at' => $family->created_at->toDateString(),
                'suspended_at' => $family->suspended_at?->toDateString(),
            ],
        ]);
    }

    // =========================================================================
    // CSV Exports
    // =========================================================================

    public function exportFamilies(): StreamedResponse
    {
        $families = Family::query()
            ->withCount('members')
            ->with('owner')
            ->latest()
            ->get();

        return response()->streamDownload(function () use ($families): void {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['ID', 'Name', 'Slug', 'Currency', 'Due Day', 'Owner', 'Members', 'Suspended', 'Created']);

            foreach ($families as $family) {
                fputcsv($handle, [
                    $family->id,
                    $family->name,
                    $family->slug,
                    $family->currency,
                    $family->due_day,
                    $family->owner?->name ?? '',
                    $family->members_count,
                    $family->suspended_at ? 'Yes' : 'No',
                    $family->created_at->toDateString(),
                ]);
            }

            fclose($handle);
        }, 'families-'.now()->format('Y-m-d').'.csv', [
            'Content-Type' => 'text/csv',
        ]);
    }

    public function exportUsers(): StreamedResponse
    {
        $users = User::query()
            ->with('family')
            ->latest()
            ->get();

        return response()->streamDownload(function () use ($users): void {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['ID', 'Name', 'Email', 'Family', 'Role', 'Category', 'Status', 'Joined']);

            foreach ($users as $user) {
                fputcsv($handle, [
                    $user->id,
                    $user->name,
                    $user->email,
                    $user->family?->name ?? '',
                    $user->role->label(),
                    $user->category?->label() ?? '',
                    $user->archived_at === null ? 'Active' : 'Archived',
                    $user->created_at->toDateString(),
                ]);
            }

            fclose($handle);
        }, 'users-'.now()->format('Y-m-d').'.csv', [
            'Content-Type' => 'text/csv',
        ]);
    }

    // =========================================================================
    // Suspend / Unsuspend Families
    // =========================================================================

    public function suspendFamily(Family $family): RedirectResponse
    {
        $family->update(['suspended_at' => now()]);

        return back()->with('success', "Family \"{$family->name}\" has been suspended.");
    }

    public function unsuspendFamily(Family $family): RedirectResponse
    {
        $family->update(['suspended_at' => null]);

        return back()->with('success', "Family \"{$family->name}\" has been unsuspended.");
    }

    // =========================================================================
    // Impersonate Users
    // =========================================================================

    public function impersonate(Request $request, User $user): RedirectResponse
    {
        if ($user->isSuperAdmin()) {
            return back()->with('error', 'Cannot impersonate another super admin.');
        }

        $request->session()->put('impersonating_from', $request->user()->id);

        Auth::login($user);

        return redirect()->route('dashboard')->with('success', "Now impersonating {$user->name}.");
    }

    public function stopImpersonating(Request $request): RedirectResponse
    {
        $originalUserId = $request->session()->pull('impersonating_from');

        if (! $originalUserId) {
            return redirect()->route('dashboard');
        }

        $originalUser = User::findOrFail($originalUserId);

        Auth::login($originalUser);

        return redirect()->route('platform.dashboard')->with('success', 'Stopped impersonating.');
    }

    // =========================================================================
    // Password Reset
    // =========================================================================

    public function sendPasswordReset(User $user): RedirectResponse
    {
        Password::sendResetLink(['email' => $user->email]);

        return back()->with('success', "Password reset email sent to {$user->email}.");
    }
}
