<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\MemberCategory;
use App\Enums\Role;
use App\Http\Requests\StoreMemberRequest;
use App\Http\Requests\UpdateMemberRequest;
use App\Models\Contribution;
use App\Models\Family;
use App\Models\FamilyMembership;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\RedirectResponse;
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
            'categories' => $this->getCategoryOptions(),
            'roles' => $this->getRoleOptions($user),
        ]);
    }

    /**
     * Store a newly created family member.
     * Admin and Financial Secretary can create ordinary members.
     */
    public function store(StoreMemberRequest $request): RedirectResponse
    {
        $validated = $request->validated();
        $attributes = $this->memberAttributes($validated);
        $currentUser = $this->user($request);

        $family = $currentUser->currentFamily ?? $currentUser->family;

        abort_unless($family instanceof Family, 403);

        $member = User::create([
            'name' => $attributes['name'],
            'email' => $attributes['email'],
            'password' => Hash::make($attributes['password'] ?? ''),
            'category' => $attributes['category'],
            'role' => $attributes['role'],
            'family_id' => $family->id,
            'current_family_id' => $family->id,
        ]);

        $member->ensureFamilyMembership($family, $attributes['role'], $attributes['category']);

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
        $family = $this->authorizeMemberInCurrentFamily($currentUser, $member);
        $membership = $this->membershipForMember($member, $family);

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
                'name' => $member->name,
                'email' => $member->email,
                'role' => $membership->role->value,
                'role_label' => $membership->role->label(),
                'category' => $membership->category?->value,
                'category_label' => $membership->categoryLabel(),
                'monthly_amount' => $membership->monthlyAmount(),
                'is_archived' => $member->isArchived(),
                'archived_at' => $member->archived_at?->toDateString(),
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
                'name' => $member->name,
                'email' => $member->email,
                'role' => $membership->role->value,
                'category' => $membership->category?->value,
            ],
            'categories' => $this->getCategoryOptions(),
            'roles' => $this->getRoleOptions(),
        ]);
    }

    /**
     * Update the specified family member.
     * Only Admin can update members.
     * Includes role change handling and last Financial Secretary warning (FR-019).
     */
    public function update(UpdateMemberRequest $request, User $member): RedirectResponse
    {
        $validated = $request->validated();
        $attributes = $this->memberAttributes($validated, includePassword: false);

        $currentUser = $this->authUser();
        $family = $this->authorizeMemberInCurrentFamily($currentUser, $member);
        $membership = $this->membershipForMember($member, $family);
        $newRole = $attributes['role'];
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
                ->whereHas('user', function (Builder $query): void {
                    $query->whereNull('archived_at');
                })
                ->count();

            if ($activeFinancialSecretaryCount === 0) {
                $warning = 'This was the last Financial Secretary. Only Admins can now record payments.';
            }
        }

        $data = [
            'name' => $attributes['name'],
            'email' => $attributes['email'],
        ];

        if ($attributes['password'] !== null) {
            $data['password'] = Hash::make($attributes['password']);
        }

        if ($member->current_family_id === $family->id || $member->family_id === $family->id) {
            $data['category'] = $attributes['category'];
            $data['role'] = $newRole;
        }

        $member->update($data);
        $membership->forceFill([
            'role' => $newRole,
            'category' => $attributes['category'],
            'family_category_id' => $member->family_category_id,
        ])->save();

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
    public function destroy(User $member): RedirectResponse
    {
        $user = $this->authUser();
        $this->authorizeMemberInCurrentFamily($user, $member);

        if (! $user->canManageMembers()) {
            abort(403);
        }

        // Cannot archive self
        if ($member->id === $user->id) {
            abort(403, 'You cannot archive yourself.');
        }

        // Cannot archive other Admins
        if ($member->isAdmin()) {
            abort(403, 'You cannot archive a Admin.');
        }

        $member->update(['archived_at' => now()]);

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
        $this->authorizeMemberInCurrentFamily($user, $member);

        if (! $user->canManageMembers()) {
            abort(403);
        }

        $member->update(['archived_at' => null]);

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
            ->whereHas('user', function (Builder $query) use ($archived): void {
                if ($archived) {
                    $query->whereNotNull('archived_at');

                    return;
                }

                $query->whereNull('archived_at');
            })
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
            'name' => $member->name,
            'email' => $member->email,
            'role' => $membership->role->value,
            'role_label' => $membership->role->label(),
            'category' => $membership->category?->value,
            'category_label' => $membership->categoryLabel(),
            'monthly_amount' => $membership->monthlyAmount(),
            'is_archived' => $member->isArchived(),
        ];

        if ($archived) {
            $payload['archived_at'] = $member->archived_at?->toDateString();
            $payload['is_archived'] = true;
        }

        return $payload;
    }

    private function membershipForMember(User $member, Family $family): FamilyMembership
    {
        $membership = $member->membershipForFamily($family);

        abort_unless($membership instanceof FamilyMembership, 404);

        return $membership;
    }

    /**
     * Get category options for forms.
     *
     * @return array<int, array{value: string, label: string, amount: int}>
     */
    private function getCategoryOptions(): array
    {
        return array_map(
            fn (MemberCategory $category): array => [
                'value' => $category->value,
                'label' => $category->label(),
                'amount' => $category->monthlyAmount(),
            ],
            MemberCategory::cases(),
        );
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

    /**
     * @return array{name: string, email: string, password: string|null, category: MemberCategory, role: Role}
     */
    private function memberAttributes(mixed $validated, bool $includePassword = true): array
    {
        $validated = is_array($validated) ? $validated : [];
        $password = $this->nullableString($validated['password'] ?? null);

        return [
            'name' => $this->stringValue($validated['name'] ?? null),
            'email' => $this->stringValue($validated['email'] ?? null),
            'password' => $includePassword ? $this->stringValue($validated['password'] ?? null) : $password,
            'category' => MemberCategory::from($this->stringValue($validated['category'] ?? MemberCategory::Employed->value)),
            'role' => Role::from($this->stringValue($validated['role'] ?? Role::Member->value)),
        ];
    }

    private function stringValue(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    private function nullableString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private function authorizeMemberInCurrentFamily(User $user, User $member): Family
    {
        $family = $user->currentFamily ?? $user->family;

        abort_unless($family instanceof Family && $member->belongsToFamily($family), 404);

        return $family;
    }
}
