<?php

declare(strict_types=1);

namespace App\Ai\Tools;

use App\Enums\AiActionKind;
use App\Models\Family;
use App\Models\User;
use App\Services\AiActionDrafts;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

class GenerateContributions implements Tool
{
    public function __construct(private User $user) {}

    /**
     * Get the description of the tool's purpose.
     */
    public function description(): string
    {
        return 'Prepares a contributions draft for human review in FamilyFund. Return the review_url to the user. This tool never posts financial records, even if asked to confirm in chat.';
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): string
    {
        if (! $this->user->isAdmin()) {
            return json_encode(['error' => 'Only family admins can generate contributions.'], JSON_THROW_ON_ERROR);
        }

        $family = $this->user->currentFamily ?? $this->user->family;

        if (! $family instanceof Family) {
            return json_encode(['error' => 'User is not associated with a family.'], JSON_THROW_ON_ERROR);
        }

        if (! app(AiActionDrafts::class)->allowed($this->user, $family, AiActionKind::Contributions)) {
            return json_encode(['error' => 'You do not have permission to prepare this action.'], JSON_THROW_ON_ERROR);
        }

        $year = $this->integerFromRequest($request['year'] ?? null, now()->year);
        $month = $this->integerFromRequest($request['month'] ?? null, now()->month);

        return json_encode(app(AiActionDrafts::class)->create($this->user, $family, AiActionKind::Contributions, [
            'year' => $year, 'month' => $month,
        ]), JSON_THROW_ON_ERROR);
    }

    /**
     * Get the tool's schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'year' => $schema->integer()->min(2020)->max(2030),
            'month' => $schema->integer()->min(1)->max(12),
        ];
    }

    private function integerFromRequest(mixed $value, int $default): int
    {
        return is_numeric($value) ? (int) $value : $default;
    }
}
