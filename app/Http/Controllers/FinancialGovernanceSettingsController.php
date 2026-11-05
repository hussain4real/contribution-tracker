<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\UpdateFinancialGovernanceRequest;
use App\Models\Family;
use App\Services\ExpenseGovernance;
use App\Support\AuditEventRecorder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FinancialGovernanceSettingsController extends Controller
{
    public function update(UpdateFinancialGovernanceRequest $request, ExpenseGovernance $governance): RedirectResponse
    {
        $user = $this->authUser();
        $family = $user->currentFamily ?? $user->family;
        abort_unless($family instanceof Family, 403);
        DB::transaction(function () use ($family, $user, $request, $governance): void {
            $locked = Family::query()->lockForUpdate()->findOrFail($family->id);
            $before = ['member_financial_visibility' => $locked->member_financial_visibility, 'expense_approval_settings' => $locked->expense_approval_settings];
            $settings = ['enabled' => $request->boolean('enabled'), 'threshold' => $request->integer('threshold'), 'approvers' => $request->integer('approvers')];
            $changed = ($locked->expense_approval_settings ?? ['enabled' => false, 'threshold' => 1, 'approvers' => 1]) !== $settings;
            if ($changed) {
                abort_unless($locked->created_by === $user->id, 403, 'Only the family owner can change approval rules.');
                if ($settings['enabled'] && ! $governance->supportsApprovals($locked)) {
                    throw ValidationException::withMessages(['enabled' => 'Approval workflows require an active Organization plan.']);
                }
            }
            if ($settings['enabled'] && $changed) {
                $eligible = $locked->memberships()->active()->whereIn('role', ['admin', 'financial_secretary'])->count();
                if ($eligible <= $settings['approvers']) {
                    throw ValidationException::withMessages(['approvers' => 'Add enough family officers for the requester and distinct independent approvers.']);
                }
            }
            $locked->update(['member_financial_visibility' => $request->string('member_financial_visibility')->toString(), 'expense_approval_settings' => $changed ? $settings : $locked->expense_approval_settings]);
            app(AuditEventRecorder::class)->record($locked, 'family.financial_settings_updated', $family->id, $user->id, before: $before, after: ['member_financial_visibility' => $locked->member_financial_visibility, 'expense_approval_settings' => $locked->expense_approval_settings]);
        }, 3);

        return back()->with('success', 'Financial visibility and approval settings saved.');
    }
}
