<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\MemberCategory;
use App\Enums\Role;
use App\Http\Requests\StoreMemberRequest;
use App\Http\Requests\UpdateMemberRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Inertia\Inertia;
use Inertia\Response;

class MemberController extends Controller
{
    /**
     * Display a listing of family members.
     * All authenticated users can view the member list.
     */
    public function index(): Response
    {
        $members = User::query()
            ->active()
            ->orderBy('name')
            ->get()
            ->map(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role->value,
                'role_label' => $user->role->label(),
                'category' => $user->category?->value,
                'category_label' => $user->category?->label(),
                'monthly_amount' => $user->getMonthlyAmountInKobo(),
                'is_archived' => $user->isArchived(),
            ]);

        $archivedMembers = User::query()
            ->archived()
            ->orderBy('name')
            ->get()
            ->map(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role->value,
                'role_label' => $user->role->label(),
                'category' => $user->category?->value,
                'category_label' => $user->category?->label(),
                'monthly_amount' => $user->getMonthlyAmountInKobo(),
                'archived_at' => $user->archived_at?->toDateString(),
                'is_archived' => true,
            ]);

        /** @var User $user */
        $user = Auth::user();

        return Inertia::render('Members/Index', [
            'members' => $members,
            'archivedMembers' => $archivedMembers,
            'canManageMembers' => $user->canManageMembers(),
        ]);
    }

    /**
     * Show the form for creating a new family member.
     * Only Super Admin can access.
     */
    public function create(): Response
    {
        /** @var User $user */
        $user = Auth::user();

        if (! $user->canManageMembers()) {
            abort(403);
        }

        return Inertia::render('Members/Create', [
            'categories' => $this->getCategoryOptions(),
            'roles' => $this->getRoleOptions(),
        ]);
    }

    /**
     * Store a newly created family member.
     * Only Super Admin can create members.
     */
    public function store(StoreMemberRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'category' => MemberCategory::from($validated['category']),
            'role' => Role::from($validated['role']),
        ]);

        return redirect()
            ->route('members.index')
            ->with('success', 'Member created successfully.');
    }

    /**
     * Display the specified family member.
     * All authenticated users can view member details.
     */
    public function show(User $member): Response
    {
        return Inertia::render('Members/Show', [
            'member' => [
                'id' => $member->id,
                'name' => $member->name,
                'email' => $member->email,
                'role' => $member->role->value,
                'role_label' => $member->role->label(),
                'category' => $member->category?->value,
                'category_label' => $member->category?->label(),
                'monthly_amount' => $member->getMonthlyAmountInKobo(),
                'is_archived' => $member->isArchived(),
                'archived_at' => $member->archived_at?->toDateString(),
                'created_at' => $member->created_at?->toDateString(),
            ],
            'canManageMembers' => Auth::user()?->canManageMembers() ?? false,
        ]);
    }

    /**
     * Show the form for editing the specified family member.
     * Only Super Admin can access.
     */
    public function edit(User $member): Response
    {
        /** @var User $user */
        $user = Auth::user();

        if (! $user->canManageMembers()) {
            abort(403);
        }

        return Inertia::render('Members/Edit', [
            'member' => [
                'id' => $member->id,
                'name' => $member->name,
                'email' => $member->email,
                'role' => $member->role->value,
                'category' => $member->category?->value,
            ],
            'categories' => $this->getCategoryOptions(),
            'roles' => $this->getRoleOptions(),
        ]);
    }

    /**
     * Update the specified family member.
     * Only Super Admin can update members.
     * Includes role change handling and last Financial Secretary warning (FR-019).
     */
    public function update(UpdateMemberRequest $request, User $member): RedirectResponse
    {
        $validated = $request->validated();

        /** @var User $currentUser */
        $currentUser = Auth::user();
        $newRole = Role::from($validated['role']);
        $oldRole = $member->role;
        $roleChanged = $oldRole !== $newRole;

        // Prevent super admin from demoting themselves
        if ($member->id === $currentUser->id && $oldRole === Role::SuperAdmin && $newRole !== Role::SuperAdmin) {
            $member->update([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'category' => MemberCategory::from($validated['category']),
                // Keep the same role
            ]);

            if (! empty($validated['password'])) {
                $member->update([
                    'password' => Hash::make($validated['password']),
                ]);
            }

            return redirect()
                ->route('members.show', $member)
                ->with('success', 'Member updated successfully.');
        }

        // Check if removing last Financial Secretary (FR-019)
        $warning = null;
        if ($roleChanged && $oldRole === Role::FinancialSecretary && $newRole !== Role::FinancialSecretary) {
            $activeFinancialSecretaryCount = User::query()
                ->active()
                ->financialSecretaries()
                ->where('id', '!=', $member->id)
                ->count();

            if ($activeFinancialSecretaryCount === 0) {
                $warning = 'This was the last Financial Secretary. Only Super Admins can now record payments.';
            }
        }

        $member->update([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'category' => MemberCategory::from($validated['category']),
            'role' => $newRole,
        ]);

        // Update password only if provided
        if (! empty($validated['password'])) {
            $member->update([
                'password' => Hash::make($validated['password']),
            ]);
        }

        $redirect = redirect()->route('members.show', $member);

        if ($warning) {
            return $redirect
                ->with('success', 'Member updated successfully.')
                ->with('warning', $warning);
        }

        return $redirect->with('success', 'Member updated successfully.');
    }

    /**
     * Archive the specified family member (soft delete).
     * Only Super Admin can archive members.
     * Cannot archive self or other Super Admins.
     */
    public function destroy(User $member): RedirectResponse
    {
        /** @var User $user */
        $user = Auth::user();

        if (! $user->canManageMembers()) {
            abort(403);
        }

        // Cannot archive self
        if ($member->id === $user->id) {
            abort(403, 'You cannot archive yourself.');
        }

        // Cannot archive other Super Admins
        if ($member->isSuperAdmin()) {
            abort(403, 'You cannot archive a Super Admin.');
        }

        $member->update(['archived_at' => now()]);

        return redirect()
            ->route('members.index')
            ->with('success', 'Member archived successfully.');
    }

    /**
     * Restore an archived family member.
     * Only Super Admin can restore members.
     */
    public function restore(User $member): RedirectResponse
    {
        /** @var User $user */
        $user = Auth::user();

        if (! $user->canManageMembers()) {
            abort(403);
        }

        $member->update(['archived_at' => null]);

        return redirect()
            ->route('members.show', $member)
            ->with('success', 'Member restored successfully.');
    }

    /**
     * Get category options for forms.
     *
     * @return array<int, array{value: string, label: string, amount: int}>
     */
    private function getCategoryOptions(): array
    {
        return collect(MemberCategory::cases())
            ->map(fn (MemberCategory $category) => [
                'value' => $category->value,
                'label' => $category->label(),
                'amount' => $category->monthlyAmountInKobo(),
            ])
            ->values()
            ->toArray();
    }

    /**
     * Get role options for forms.
     *
     * @return array<int, array{value: string, label: string}>
     */
    private function getRoleOptions(): array
    {
        return collect(Role::cases())
            ->map(fn (Role $role) => [
                'value' => $role->value,
                'label' => $role->label(),
            ])
            ->values()
            ->toArray();
    }
}
