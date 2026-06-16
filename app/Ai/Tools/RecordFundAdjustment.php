<?php

declare(strict_types=1);

namespace App\Ai\Tools;

use App\Models\Family;
use App\Models\FundAdjustment;
use App\Models\User;
use App\Support\CurrencyFormatter;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

class RecordFundAdjustment implements Tool
{
    public function __construct(private User $user) {}

    /**
     * Get the description of the tool's purpose.
     */
    public function description(): string
    {
        return 'Records a fund adjustment for the family (e.g., interest earned, donations received, corrections). Requires amount (whole number), description, and date. Always call without confirmed=true first to preview.';
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): string
    {
        if (! $this->user->canRecordPayments()) {
            return json_encode(['error' => 'You do not have permission to record fund adjustments. Only admins and financial secretaries can do this.'], JSON_THROW_ON_ERROR);
        }

        $amount = $this->nullableIntegerFromRequest($request['amount'] ?? null);
        $description = $this->nullableStringFromRequest($request['description'] ?? null);
        $recordedAt = $this->stringFromRequest($request['recorded_at'] ?? null, now()->toDateString());
        $confirmed = ($request['confirmed'] ?? false) === true;

        if (! $amount || $amount < 1) {
            return json_encode(['error' => 'Amount is required and must be at least 1.'], JSON_THROW_ON_ERROR);
        }

        if (! $description) {
            return json_encode(['error' => 'A description is required for the fund adjustment.'], JSON_THROW_ON_ERROR);
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
                'message' => "I'll record a fund adjustment of {$formattedAmount} for \"{$description}\" on {$recordedAt}. Please confirm to proceed.",
                'details' => [
                    'amount' => $amount,
                    'description' => $description,
                    'recorded_at' => $recordedAt,
                ],
            ], JSON_THROW_ON_ERROR);
        }

        $adjustment = FundAdjustment::create([
            'family_id' => $family->id,
            'amount' => $amount,
            'description' => $description,
            'recorded_at' => $recordedAt,
            'recorded_by' => $this->user->id,
        ]);

        return json_encode([
            'status' => 'success',
            'message' => "Fund adjustment of {$formattedAmount} for \"{$description}\" has been recorded successfully.",
            'adjustment_id' => $adjustment->id,
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
            'recorded_at' => $schema->string(),
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
