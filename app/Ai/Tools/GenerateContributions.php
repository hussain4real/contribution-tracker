<?php

namespace App\Ai\Tools;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Artisan;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class GenerateContributions implements Tool
{
    public function __construct(private User $user) {}

    /**
     * Get the description of the tool's purpose.
     */
    public function description(): Stringable|string
    {
        return 'Generates contribution records for all active family members for a given month and year. This creates the expected contribution entries that members need to pay. Only admins can use this. Always call without confirmed=true first to preview.';
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): Stringable|string
    {
        if (! $this->user->isAdmin()) {
            return json_encode(['error' => 'Only family admins can generate contributions.'], JSON_THROW_ON_ERROR);
        }

        $year = $request['year'] ?? now()->year;
        $month = $request['month'] ?? now()->month;
        $confirmed = $request['confirmed'] ?? false;

        $periodLabel = Carbon::createFromDate($year, $month, 1)->format('F Y');

        if (! $confirmed) {
            return json_encode([
                'status' => 'confirmation_required',
                'message' => "I'll generate contribution records for all active family members for {$periodLabel}. This will create expected contribution entries for each member based on their category. Please confirm to proceed.",
                'details' => [
                    'year' => $year,
                    'month' => $month,
                    'period' => $periodLabel,
                ],
            ], JSON_THROW_ON_ERROR);
        }

        Artisan::call('contributions:generate', [
            '--year' => $year,
            '--month' => $month,
            '--family' => $this->user->family_id,
        ]);

        $output = trim(Artisan::output());

        return json_encode([
            'status' => 'success',
            'message' => "Contributions have been generated for {$periodLabel}.",
            'output' => $output,
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * Get the tool's schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'year' => $schema->integer()->min(2020)->max(2030),
            'month' => $schema->integer()->min(1)->max(12),
            'confirmed' => $schema->boolean(),
        ];
    }
}
