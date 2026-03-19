<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Contribution;
use App\Models\Expense;
use App\Models\Family;
use App\Models\Payment;
use App\Models\User;
use Inertia\Inertia;
use Inertia\Response;

class PlatformAdminController extends Controller
{
    public function index(): Response
    {
        $totalFamilies = Family::count();
        $totalUsers = User::count();
        $totalPayments = (int) Payment::sum('amount');
        $totalExpenses = (int) Expense::sum('amount');
        $totalContributions = Contribution::count();

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

        return Inertia::render('Platform/Dashboard', [
            'stats' => [
                'total_families' => $totalFamilies,
                'total_users' => $totalUsers,
                'total_payments' => $totalPayments,
                'total_expenses' => $totalExpenses,
                'total_contributions' => $totalContributions,
            ],
            'recent_families' => $recentFamilies,
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
                'created_at' => $family->created_at->toDateString(),
            ]);

        return Inertia::render('Platform/Families', [
            'families' => $families,
        ]);
    }

    public function showFamily(Family $family): Response
    {
        $family->load(['owner', 'categories', 'members' => fn ($q) => $q->orderBy('name')]);

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
                ]),
                'created_at' => $family->created_at->toDateString(),
            ],
        ]);
    }
}
