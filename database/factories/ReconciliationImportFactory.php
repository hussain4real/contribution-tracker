<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ReconciliationImportStatus;
use App\Models\Family;
use App\Models\ReconciliationImport;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReconciliationImport>
 */
class ReconciliationImportFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<model-property<ReconciliationImport>, mixed>
     */
    public function definition(): array
    {
        return [
            'family_id' => Family::factory(),
            'uploaded_by' => null,
            'disk' => 'local',
            'path' => 'reconciliation/'.fake()->uuid().'.csv',
            'original_name' => 'statement.csv',
            'mime_type' => 'text/csv',
            'size' => 100,
            'file_fingerprint' => hash('sha256', fake()->uuid()),
            'delimiter' => ',',
            'headers' => ['date', 'amount', 'direction', 'reference', 'description'],
            'preview_rows' => [],
            'mapping' => null,
            'status' => ReconciliationImportStatus::Previewed,
        ];
    }
}
