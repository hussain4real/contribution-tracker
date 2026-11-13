<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ExpenseBudget;
use App\Models\ExpenseCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ExpenseBudget> */
class ExpenseBudgetFactory extends Factory
{
    public function definition(): array
    {
        return [
            'expense_category_id' => ExpenseCategory::factory(),
            'family_id' => fn (array $attributes): int => ExpenseCategory::query()->whereKey($attributes['expense_category_id'])->sole()->family_id,
            'month' => now()->startOfMonth()->toDateString(), 'amount' => 10000,
        ];
    }
}
