<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ExpenseRequest;
use App\Models\Family;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ExpenseRequest> */
class ExpenseRequestFactory extends Factory
{
    /** @return array<model-property<ExpenseRequest>, mixed> */
    public function definition(): array
    {
        return ['family_id' => Family::factory(), 'requested_by' => User::factory(), 'amount' => 1000, 'description' => fake()->sentence(), 'spent_at' => now()->toDateString(), 'status' => 'submitted', 'required_approvals' => 1, 'idempotency_key' => fake()->uuid()];
    }
}
