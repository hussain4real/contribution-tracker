<?php

namespace App\Ai\Tools;

use App\Models\Expense;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class RecordExpense implements Tool
{
    public function __construct(private User $user) {}

    /**
     * Get the description of the tool's purpose.
     */
    public function description(): Stringable|string
    {
        return 'Records a new expense for the family. Requires amount (whole number in Naira), description, and date. Always call without confirmed=true first to preview the action.';
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): Stringable|string
    {
        if (! $this->user->canRecordPayments()) {
            return json_encode(['error' => 'You do not have permission to record expenses. Only admins and financial secretaries can do this.'], JSON_THROW_ON_ERROR);
        }

        $amount = $request['amount'] ?? null;
        $description = $request['description'] ?? null;
        $spentAt = $request['spent_at'] ?? now()->toDateString();
        $confirmed = $request['confirmed'] ?? false;

        if (! $amount || $amount < 1) {
            return json_encode(['error' => 'Amount is required and must be at least ₦1.'], JSON_THROW_ON_ERROR);
        }

        if (! $description) {
            return json_encode(['error' => 'A description is required for the expense.'], JSON_THROW_ON_ERROR);
        }

        $currency = $this->user->family?->currency ?? '₦';
        $formattedAmount = $currency.number_format($amount, 2);

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
            'family_id' => $this->user->family_id,
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
}
