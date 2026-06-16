<?php

declare(strict_types=1);

namespace App\Ai\Tools;

use App\Models\Expense;
use App\Models\Family;
use App\Models\User;
use App\Support\CurrencyFormatter;
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
        return 'Records a new expense for the family. Requires amount (whole number), description, and date. Always call without confirmed=true first to preview the action.';
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
        $confirmed = ($request['confirmed'] ?? false) === true;

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

        $currency = $family->currency;
        $formattedAmount = CurrencyFormatter::format($amount, $currency);

        if (! $confirmed) {
            return json_encode([
                'status' => 'confirmation_required',
                'message' => "I'll record an expense of {$formattedAmount} for \"{$description}\" on {$spentAt}. Please confirm to proceed.",
                'details' => [
                    'amount' => $amount,
                    'description' => $description,
                    'spent_at' => $spentAt,
                ],
            ], JSON_THROW_ON_ERROR);
        }

        $expense = Expense::create([
            'family_id' => $family->id,
            'amount' => $amount,
            'description' => $description,
            'spent_at' => $spentAt,
            'recorded_by' => $this->user->id,
        ]);

        return json_encode([
            'status' => 'success',
            'message' => "Expense of {$formattedAmount} for \"{$description}\" has been recorded successfully.",
            'expense_id' => $expense->id,
        ], JSON_THROW_ON_ERROR);
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
            'confirmed' => $schema->boolean(),
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
