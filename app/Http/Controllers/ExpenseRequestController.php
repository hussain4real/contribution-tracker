<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\StoreExpenseApprovalRequest;
use App\Http\Requests\StoreExpenseRequest;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\ExpenseDocument;
use App\Models\ExpenseRequest;
use App\Models\Family;
use App\Services\ExpenseBudgetSpending;
use App\Services\ExpenseGovernance;
use App\Support\AuditEventRecorder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ExpenseRequestController extends Controller
{
    private function officerFamily(?int $familyId = null): Family
    {
        $user = $this->authUser();
        $family = $user->currentFamily ?? $user->family;
        abort_unless($family instanceof Family && $user->canRecordPayments(), 403);
        abort_if($familyId !== null && $familyId !== $family->id, 403);

        return $family;
    }

    public function index(Request $httpRequest, ExpenseGovernance $governance, ExpenseBudgetSpending $spending): Response
    {
        $family = $this->officerFamily();
        $user = $this->authUser();
        $requests = ExpenseRequest::query()->where('family_id', $family->id)
            ->when($httpRequest->filled('entry'), fn ($query) => $query->whereKey($httpRequest->integer('entry')))
            ->with(['requester:id,name', 'approvals.user:id,name', 'expense.reversal', 'documents', 'category'])
            ->latest('id')->paginate(20)->through(function (ExpenseRequest $request) use ($family, $user, $governance, $spending): array {
                $required = $governance->requiredForRequest($family, $request);
                $approved = $request->approvals->where('decision', 'approved')->where('user_id', '!=', $request->requested_by)->count();
                $open = in_array($request->status, ['submitted', 'approved'], true) && $request->expense === null;

                return [
                    'id' => $request->id, 'amount' => $request->amount, 'description' => $request->description,
                    'spent_at' => $request->spent_at->toDateString(), 'requester' => $request->requester->name ?? 'Former member',
                    'status' => $open ? ($approved >= $required ? 'approved' : 'submitted') : $request->status, 'required' => $required, 'approved' => $approved,
                    'can_decide' => $open && $request->requested_by !== $user->id && ! $request->approvals->contains('user_id', $user->id),
                    'can_pay' => $open && $approved >= $required,
                    'category' => $request->category->name ?? 'Uncategorized',
                    'budget_advisory' => $open ? $spending->advisory($family, $request->expense_category_id, $request->spent_at->format('Y-m'), $request->amount, $approved >= $required) : null,
                    'expense_id' => $request->expense?->id,
                    'is_reversed' => $request->expense?->isReversed() ?? false,
                    'decisions' => $request->approvals->map(fn ($decision): array => [
                        'by' => $decision->user->name ?? 'Former member', 'decision' => $decision->decision, 'reason' => $decision->reason,
                    ]),
                    'documents' => $request->documents->where('ready', true)->map(fn (ExpenseDocument $document): array => [
                        'id' => $document->id, 'filename' => $document->filename,
                    ])->values(),
                ];
            });

        return Inertia::render('Expenses/Requests', [
            'requests' => $requests,
            'can_submit' => $governance->supportsApprovals($family),
            'categories' => ExpenseCategory::query()->where('family_id', $family->id)->orderBy('name')->get(['id', 'name']),
            'idempotencyKey' => (string) Str::uuid(),
            'idempotencyScope' => "expense-request-{$family->id}-{$user->id}",
        ]);
    }

    public function store(StoreExpenseRequest $request, ExpenseGovernance $governance): RedirectResponse
    {
        $family = $this->officerFamily();
        abort_unless($governance->supportsApprovals($family), 403, 'Expense requests require an active Organization plan.');
        DB::transaction(function () use ($family, $request, $governance): void {
            $lockedFamily = Family::query()->lockForUpdate()->findOrFail($family->id);
            abort_unless($governance->supportsApprovals($lockedFamily), 403);
            $key = $request->string('idempotency_key')->toString();
            $existing = ExpenseRequest::query()->where('family_id', $family->id)->where('idempotency_key', $key)->first();
            if ($existing instanceof ExpenseRequest) {
                if ($existing->requested_by !== $this->authUser()->id || $existing->amount !== $request->integer('amount')
                    || $existing->expense_category_id !== ($request->filled('expense_category_id') ? $request->integer('expense_category_id') : null)
                    || $existing->description !== $request->string('description')->toString()
                    || $existing->spent_at->toDateString() !== $request->date('spent_at')?->toDateString()) {
                    throw ValidationException::withMessages(['idempotency_key' => 'This request key was already used.']);
                }

                return;
            }
            if (Expense::query()->where('family_id', $family->id)->where('idempotency_key', $key)->exists()) {
                throw ValidationException::withMessages(['idempotency_key' => 'This key already belongs to a posted expense.']);
            }
            $required = $governance->requiredApprovals($lockedFamily, $request->integer('amount'));
            $new = ExpenseRequest::query()->create([
                'family_id' => $family->id, 'requested_by' => $this->authUser()->id,
                'expense_category_id' => $request->filled('expense_category_id') ? $request->integer('expense_category_id') : null,
                'amount' => $request->integer('amount'), 'description' => $request->string('description')->toString(),
                'spent_at' => $request->string('spent_at')->toString(),
                'status' => $required === 0 ? 'approved' : 'submitted', 'required_approvals' => $required,
                'idempotency_key' => $key,
            ]);
            app(AuditEventRecorder::class)->record($new, 'expense_request.submitted', $family->id);
        });

        return back()->with('success', 'Expense request submitted. The family balance changes only when payment is recorded.');
    }

    public function decide(StoreExpenseApprovalRequest $request, ExpenseRequest $expenseRequest, ExpenseGovernance $governance): RedirectResponse
    {
        $this->officerFamily($expenseRequest->family_id);
        $governance->decide($expenseRequest, $this->authUser(), $request->string('decision')->toString(), $request->filled('reason') ? $request->string('reason')->trim()->toString() : null);

        return back()->with('success', 'Expense decision recorded.');
    }

    public function pay(ExpenseRequest $expenseRequest, ExpenseGovernance $governance): RedirectResponse
    {
        $this->officerFamily($expenseRequest->family_id);
        $governance->pay($expenseRequest, $this->authUser());

        return back()->with('success', 'Expense payment recorded.');
    }
}
