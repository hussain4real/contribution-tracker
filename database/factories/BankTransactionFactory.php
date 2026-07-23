<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\BankTransactionDirection;
use App\Enums\ReconciliationStatus;
use App\Models\BankTransaction;
use App\Models\Family;
use App\Models\ReconciliationImport;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BankTransaction>
 */
class BankTransactionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<model-property<BankTransaction>, mixed>
     */
    public function definition(): array
    {
        return [
            'family_id' => Family::factory(),
            'reconciliation_import_id' => ReconciliationImport::factory(),
            'row_number' => 2,
            'direction' => BankTransactionDirection::Credit,
            'transacted_at' => now()->toDateString(),
            'amount' => fake()->numberBetween(100, 10000),
            'reference' => fake()->bothify('REF-####'),
            'description' => fake()->sentence(),
            'source_account' => 'Main account',
            'raw_data' => [],
            'row_fingerprint' => hash('sha256', fake()->uuid()),
            'status' => ReconciliationStatus::Unmatched,
        ];
    }
}
