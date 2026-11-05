<?php

declare(strict_types=1);

namespace App\Ai\Tools;

use App\Enums\AiActionKind;
use App\Models\Family;
use App\Models\User;
use App\Services\AiActionDrafts;
use App\Services\ExpenseGovernance;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

class RecordExpense implements Tool
{
    public function __construct(private User $user) {}

    /**
     * Get the description of the tool's purpose.
     */
    public function description(): string
    {
        return 'Prepares a expense draft for human review in FamilyFund. Return the review_url to the user. This tool never posts financial records, even if asked to confirm in chat.';
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): string
    {
        if (! $this->user->canRecordPayments()) {
            return json_encode(['error' => 'You do not have permission to record expenses. Only admins and financial secretaries can do this.'], JSON_THROW_ON_ERROR);
        }

        $amount = $this->nullableIntegerFromRequest($request['amount'] ?? null);
        $description = $this->nullableStringFromRequest($request['description'] ?? null);
        $spentAt = $this->stringFromRequest($request['spent_at'] ?? null, now()->toDateString());

        if (! $amount || $amount < 1) {
            return json_encode(['error' => 'Amount is required and must be at least 1.'], JSON_THROW_ON_ERROR);
        }

        if (! $description) {
            return json_encode(['error' => 'A description is required for the expense.'], JSON_THROW_ON_ERROR);
        }

        $family = $this->user->currentFamily ?? $this->user->family;

        if (! $family instanceof Family) {
            return json_encode(['error' => 'User is not associated with a family.'], JSON_THROW_ON_ERROR);
        }

        if (! app(AiActionDrafts::class)->allowed($this->user, $family, AiActionKind::Expense)) {
            return json_encode(['error' => 'You do not have permission to prepare this action.'], JSON_THROW_ON_ERROR);
        }

        if (app(ExpenseGovernance::class)->requiredApprovals($family, $amount) > 0) {
            return json_encode([
                'status' => 'approval_required',
                'message' => 'Submit an expense request and obtain independent approvals before recording this expense.',
                'url' => route('expense-requests.index', ['current_family' => $family->slug]),
            ], JSON_THROW_ON_ERROR);
        }

        return json_encode(app(AiActionDrafts::class)->create($this->user, $family, AiActionKind::Expense, [
            'amount' => $amount, 'description' => $description, 'spent_at' => $spentAt,
        ]), JSON_THROW_ON_ERROR);
    }

    /**
     * Get the tool's schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'amount' => $schema->integer()->min(1)->required(),
            'description' => $schema->string()->required(),
            'spent_at' => $schema->string(),
        ];
    }

    private function nullableIntegerFromRequest(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    private function nullableStringFromRequest(mixed $value): ?string
    {
        return is_scalar($value) && (string) $value !== '' ? (string) $value : null;
    }

    private function stringFromRequest(mixed $value, string $default): string
    {
        return is_scalar($value) ? (string) $value : $default;
    }
}
