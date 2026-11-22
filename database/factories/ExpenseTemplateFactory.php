<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ExpenseTemplate;
use App\Models\Family;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<ExpenseTemplate> */
class ExpenseTemplateFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'family_id' => Family::factory(),
            'amount' => 1000,
            'description' => fake()->sentence(),
            'day_of_month' => 1,
            'next_run_on' => now('UTC')->startOfMonth()->toDateString(),
            'idempotency_key' => (string) Str::uuid(),
        ];
    }
}
