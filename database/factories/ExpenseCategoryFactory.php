<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ExpenseCategory;
use App\Models\Family;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ExpenseCategory> */
class ExpenseCategoryFactory extends Factory
{
    public function definition(): array
    {
        return ['family_id' => Family::factory(), 'name' => fake()->unique()->word()];
    }
}
