<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\AssignFamilyCategory;
use App\Enums\MemberCategory;
use App\Enums\Role;
use App\Http\Requests\StoreMemberRequest;
use App\Http\Requests\UpdateMemberRequest;
use App\Models\Contribution;
use App\Models\Family;
use App\Models\FamilyCategory;
use App\Models\FamilyMembership;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
        $currentUser = $this->authUser();
        $family = $currentUser->currentFamily ?? $currentUser->family;

        abort_unless($family instanceof Family, 403);

        $members = $this->familyMemberships($family)
            ->map(fn (FamilyMembership $membership): array => $this->memberIndexPayload($membership));

        $archivedMembers = $this->familyMemberships($family, archived: true)
            ->map(fn (FamilyMembership $membership): array => $this->memberIndexPayload($membership, archived: true));

        return Inertia::render('Members/Index', [
            'members' => $members,
            'archivedMembers' => $archivedMembers,
            'canAddMembers' => $currentUser->canAddMembers(),
            'canManageMembers' => $currentUser->canManageMembers(),
        ]);
    }

    /**
     * Show the form for creating a new family member.
     * Admin and Financial Secretary can access.
     */
    public function create(): Response
    {
        $user = $this->authUser();

        if (! $user->canAddMembers()) {
            abort(403);
        }

        return Inertia::render('Members/Create', [
            'categories' => $this->getCategoryOptions($this->currentFamilyFor($user)),
            'roles' => $this->getRoleOptions($user),
        ]);
    }

    /**
     * Store a newly created family member.
     * Admin and Financial Secretary can create ordinary members.
     */
    public function store(StoreMemberRequest $request, AssignFamilyCategory $assignFamilyCategory): RedirectResponse
    {
        $request->validated();
        $currentUser = $this->user($request);
        $family = $this->currentFamilyFor($currentUser);
        $category = $family->categories()->findOrFail($request->integer('family_category_id'));
        $role = Role::from($request->string('role')->toString());
        $name = $request->string('name')->toString();
        $email = $request->string('email')->toString();
        $password = $request->string('password')->toString();

        DB::transaction(function () use (
            $family,
            $category,
            $role,
            $name,
            $email,
            $password,
            $currentUser,
            $assignFamilyCategory,
        ): void {
            $member = User::query()->create([
                'name' => $name,
                'email' => $email,
                'password' => Hash::make($password),
                'must_change_password_at' => now(),
                'category' => MemberCategory::tryFrom($category->slug),
                'role' => $role,
                'family_id' => $family->id,
                'current_family_id' => $family->id,
                'family_category_id' => $category->id,
            ]);

            $membership = $member->ensureFamilyMembership(
                family: $family,
                role: $role,
                familyCategoryId: $category->id,
            );
            $membership->forceFill(['display_name' => $name])->save();
            $assignFamilyCategory->handle($membership, $category, $currentUser, effectiveImmediately: true);
        });

        return redirect()
            ->route('members.index')
            ->with('success', 'Member created successfully.');
    }

    /**
     * Display the specified family member.
     * All authenticated users can view basic member info.
     * Contribution history is only visible to the member themselves,
     * Admin, or Financial Secretary.
     */
    public function show(User $member): Response
    {
        $currentUser = $this->authUser();
        $family = $this->authorizeMemberInCurrentFamily($currentUser, $member, includeArchived: true);
        $membership = $this->membershipForMember($member, $family, includeArchived: true);

        // Determine if user can view contribution history
        // (own profile OR has elevated permissions)
        $canViewContributions = $currentUser->canViewAllMembers() || $currentUser->id === $member->id;

        // Only load contributions if user has permission
        $contributions = [];
        $totalExpected = 0;
        $totalPaid = 0;

        if ($canViewContributions) {
            $contributionModels = $member->contributions()
                ->where('family_id', $family->id)
                ->with('payments.recorder')
                ->orderByDesc('year')
                ->orderByDesc('month')
                ->take(12) // Last 12 months
                ->get();

            $contributions = $contributionModels
                ->map(fn (Contribution $contribution): array => [
                    'id' => $contribution->id,
                    'year' => $contribution->year,
                    'month' => $contribution->month,
                    'period_label' => $contribution->period_label,
                    'expected_amount' => $contribution->expected_amount,
                    'total_paid' => $contribution->total_paid,
                    'balance' => $contribution->balance,
                    'status' => $contribution->status->value,
                    'status_label' => $contribution->status->label(),
                    'due_date' => $contribution->due_date->toDateString(),
                    'payments' => $contribution->payments->map(fn (Payment $payment): array => [
                        'id' => $payment->id,
                        'amount' => $payment->amount,
                        'paid_at' => $payment->paid_at->toDateString(),
                        'notes' => $payment->notes,
                        'recorder' => [
                            'name' => $payment->recorder?->name,
                        ],
                    ])->values()->all(),
                ])->values()->all();

            // Calculate summary statistics
            $totalExpected = (int) $contributionModels->sum(fn (Contribution $contribution): int => $contribution->expected_amount);
            $totalPaid = (int) $contributionModels->sum(fn (Contribution $contribution): int => $contribution->total_paid);
        }

        return Inertia::render('Members/Show', [
            'member' => [
                'id' => $member->id,
                'name' => $membership->displayName(),
                'email' => $member->email,
                'role' => $membership->role->value,
                'role_label' => $membership->role->label(),
                'category' => $membership->family_category_id,
                'category_label' => $membership->categoryLabel(),
                'monthly_amount' => $membership->monthlyAmount(),
                'is_archived' => $membership->isArchived(),
                'archived_at' => $membership->archived_at?->toDateString(),
                'archive_reason' => $membership->archive_reason,
                'created_at' => $member->created_at?->toDateString(),
                'whatsapp_verified' => $member->whatsapp_verified_at !== null,
                'web_push_subscribed' => $member->pushSubscriptions()->exists(),
            ],
            'contributions' => $contributions,
            'summary' => [
                'total_expected' => $totalExpected,
                'total_paid' => $totalPaid,
                'total_outstanding' => $totalExpected - $totalPaid,
                'contribution_count' => count($contributions),
            ],
            'canManageMembers' => $currentUser->canManageMembers(),
            'canViewContributions' => $canViewContributions,
            'canSendEmailReminder' => $currentUser->canRecordPayments(),
            'canSendWhatsAppReminder' => $currentUser->canRecordPayments(),
            'canSendWebPushReminder' => $currentUser->canRecordPayments(),
        ]);
    }

    /**
     * Show the form for editing the specified family member.
     * Only Admin can access.
     */
    public function edit(User $member): Response
    {
        $user = $this->authUser();
        $family = $this->authorizeMemberInCurrentFamily($user, $member);
        $membership = $this->membershipForMember($member, $family);

        if (! $user->canManageMembers()) {
            abort(403);
        }

        return Inertia::render('Members/Edit', [
            'member' => [
                'id' => $member->id,
                'name' => $membership->displayName(),
                'email' => $member->email,
                'role' => $membership->role->value,
                'category' => $this->categorySlug($membership),
                'family_category_id' => $membership->family_category_id,
            ],
            'categories' => $this->getCategoryOptions($family),
            'roles' => $this->getRoleOptions(),
        ]);
    }

    /**
     * Update the specified family member.
     * Only Admin can update members.
     * Includes role change handling and last Financial Secretary warning (FR-019).
     */
    public function update(
        UpdateMemberRequest $request,
        User $member,
        AssignFamilyCategory $assignFamilyCategory,
    ): RedirectResponse {
        $request->validated();
        $currentUser = $this->authUser();
        $family = $this->authorizeMemberInCurrentFamily($currentUser, $member);
        $membership = $this->membershipForMember($member, $family);
        $newRole = Role::from($request->string('role')->toString());
        $newCategory = $family->categories()->findOrFail($request->integer('family_category_id'));
        $oldRole = $membership->role;
        $roleChanged = $oldRole !== $newRole;

        // Prevent super admin from demoting themselves
        if ($member->id === $currentUser->id && $oldRole === Role::Admin && $newRole !== Role::Admin) {
            $newRole = $oldRole;
            $roleChanged = false;
        }

        // Check if removing last Financial Secretary (FR-019)
        $warning = null;
        if ($roleChanged && $oldRole === Role::FinancialSecretary && $newRole !== Role::FinancialSecretary) {
            $activeFinancialSecretaryCount = FamilyMembership::query()
                ->where('family_id', $family->id)
                ->where('role', Role::FinancialSecretary)
                ->where('user_id', '!=', $member->id)
                ->active()
                ->count();

            if ($activeFinancialSecretaryCount === 0) {
                $warning = 'This was the last Financial Secretary. Only Admins can now record payments.';
            }
        }

        $membership->forceFill([
            'display_name' => $request->string('display_name')->toString(),
            'role' => $newRole,
        ])->save();

        if ($membership->family_category_id !== $newCategory->id) {
            $membership = $assignFamilyCategory->handle(
                $membership,
                $newCategory,
                $currentUser,
                effectiveImmediately: $request->boolean('effective_immediately'),
            );
        }

        if ($member->current_family_id === $family->id || $member->family_id === $family->id) {
            $member->forceFill([
                'role' => $membership->role,
                'category' => MemberCategory::tryFrom($newCategory->slug),
                'family_category_id' => $newCategory->id,
            ])->save();
        }

        $redirect = redirect()->route('members.show', $member)
            ->with('success', 'Member updated successfully.');

        if ($warning) {
            $redirect->with('warning', $warning);
        }

        return $redirect;
    }

    /**
     * Archive the specified family member (soft delete).
     * Only Admin can archive members.
     * Cannot archive self or other Admins.
     */
    public function destroy(Request $request, User $member): RedirectResponse
    {
        $user = $this->authUser();
        $family = $this->authorizeMemberInCurrentFamily($user, $member);
        $membership = $this->membershipForMember($member, $family);

        if (! $user->canManageMembers()) {
            abort(403);
        }

        // Cannot archive self
        if ($member->id === $user->id) {
            abort(403, 'You cannot archive yourself.');
        }

        // Cannot archive other Admins
        if ($membership->role === Role::Admin) {
            abort(403, 'You cannot archive an administrator.');
        }

        $membership->forceFill([
            'archived_at' => now(),
            'archived_by' => $user->id,
            'archive_reason' => $request->filled('reason')
                ? $request->string('reason')->toString()
                : 'Archived by family administrator.',
        ])->save();

        return redirect()
            ->route('members.index')
            ->with('success', 'Member archived successfully.');
    }

    /**
     * Restore an archived family member.
     * Only Admin can restore members.
     */
    public function restore(User $member): RedirectResponse
    {
        $user = $this->authUser();
        $family = $this->authorizeMemberInCurrentFamily($user, $member, includeArchived: true);
        $membership = $this->membershipForMember($member, $family, includeArchived: true);

        if (! $user->canManageMembers()) {
            abort(403);
        }

        $membership->forceFill([
            'archived_at' => null,
            'archived_by' => null,
            'archive_reason' => null,
        ])->save();

        return redirect()
            ->route('members.show', $member)
            ->with('success', 'Member restored successfully.');
    }

    /**
     * @return EloquentCollection<int, FamilyMembership>
     */
    private function familyMemberships(Family $family, bool $archived = false): EloquentCollection
    {
        return $family->memberships()
            ->with(['user', 'familyCategory'])
            ->when($archived, fn (Builder $query): Builder => $query->archived())
            ->when(! $archived, fn (Builder $query): Builder => $query->active())
            ->join('users', 'users.id', '=', 'family_members.user_id')
            ->orderBy('users.name')
            ->select('family_members.*')
            ->get();
    }

    /**
     * @return array<string, mixed>
     */
    private function memberIndexPayload(FamilyMembership $membership, bool $archived = false): array
    {
        $member = $membership->user;

        $payload = [
            'id' => $member->id,
            'name' => $membership->displayName(),
            'email' => $member->email,
            'role' => $membership->role->value,
            'role_label' => $membership->role->label(),
            'category' => $this->categorySlug($membership),
            'family_category_id' => $membership->family_category_id,
            'category_label' => $membership->categoryLabel(),
            'monthly_amount' => $membership->monthlyAmount(),
            'is_archived' => $membership->isArchived(),
        ];

        if ($archived) {
            $payload['archived_at'] = $membership->archived_at?->toDateString();
            $payload['archive_reason'] = $membership->archive_reason;
            $payload['is_archived'] = true;
        }

        return $payload;
    }

    private function membershipForMember(User $member, Family $family, bool $includeArchived = false): FamilyMembership
    {
        $membership = $includeArchived
            ? $member->membershipForFamilyIncludingArchived($family)
            : $member->membershipForFamily($family);

        abort_unless($membership instanceof FamilyMembership, 404);

        return $membership;
    }

    /**
     * Get category options for forms.
     *
     * @return array<int, array{value: int, label: string, amount: int}>
     */
    private function getCategoryOptions(Family $family): array
    {
        return $family->categories()
            ->orderBy('sort_order')
            ->get()
            ->map(fn (FamilyCategory $category): array => [
                'value' => $category->id,
                'label' => $category->name,
                'amount' => $category->monthly_amount,
            ])
            ->all();
    }

    private function categorySlug(FamilyMembership $membership): ?string
    {
        if ($membership->familyCategory instanceof FamilyCategory) {
            return $membership->familyCategory->slug;
        }

        return $membership->category?->value;
    }

    /**
     * Get role options for forms.
     *
     * @return array<int, array{value: string, label: string}>
     */
    private function getRoleOptions(?User $user = null): array
    {
        $roles = $user instanceof User && ! $user->canManageRoles()
            ? [Role::Member]
            : Role::cases();

        return array_map(
            fn (Role $role): array => [
                'value' => $role->value,
                'label' => $role->label(),
            ],
            $roles,
        );
    }

    private function authorizeMemberInCurrentFamily(
        User $user,
        User $member,
        bool $includeArchived = false,
    ): Family {
        $family = $this->currentFamilyFor($user);
        $belongsToFamily = $includeArchived
            ? $member->belongsToFamilyIncludingArchived($family)
            : $member->belongsToFamily($family);

        abort_unless($belongsToFamily, 404);

        return $family;
    }

    private function currentFamilyFor(User $user): Family
    {
        $family = $user->currentFamily ?? $user->family;

        abort_unless($family instanceof Family && $user->belongsToFamily($family), 403);

        return $family;
    }
}
