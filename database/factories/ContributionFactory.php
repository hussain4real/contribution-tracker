<?php

namespace Database\Factories;

use App\Enums\MemberCategory;
use App\Models\Contribution;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Contribution>
 */
class ContributionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'year' => now()->year,
            'month' => now()->month,
            'expected_amount' => MemberCategory::Employed->monthlyAmountInKobo(),
        ];
    }

    // =========================================================================
    // Period States
    // =========================================================================

    /**
     * Create a contribution for the current month.
     */
    public function currentMonth(): static
    {
        return $this->state(fn (array $attributes) => [
            'year' => now()->year,
            'month' => now()->month,
        ]);
    }

    /**
     * Create a contribution for a specific month and year.
     */
    public function forMonth(int $year, int $month): static
    {
        return $this->state(fn (array $attributes) => [
            'year' => $year,
            'month' => $month,
        ]);
    }

    /**
     * Create a contribution for the previous month.
     */
    public function previousMonth(): static
    {
        $previous = now()->subMonth();

        return $this->state(fn (array $attributes) => [
            'year' => $previous->year,
            'month' => $previous->month,
        ]);
    }

    /**
     * Create a contribution for the next month.
     */
    public function nextMonth(): static
    {
        $next = now()->addMonth();

        return $this->state(fn (array $attributes) => [
            'year' => $next->year,
            'month' => $next->month,
        ]);
    }

    // =========================================================================
    // Amount States
    // =========================================================================

    /**
     * Create a contribution for an employed member (₦4,000).
     */
    public function employed(): static
    {
        return $this->state(fn (array $attributes) => [
            'expected_amount' => MemberCategory::Employed->monthlyAmountInKobo(),
        ]);
    }

    /**
     * Create a contribution for an unemployed member (₦2,000).
     */
    public function unemployed(): static
    {
        return $this->state(fn (array $attributes) => [
            'expected_amount' => MemberCategory::Unemployed->monthlyAmountInKobo(),
        ]);
    }

    /**
     * Create a contribution for a student member (₦1,000).
     */
    public function student(): static
    {
        return $this->state(fn (array $attributes) => [
            'expected_amount' => MemberCategory::Student->monthlyAmountInKobo(),
        ]);
    }

    /**
     * Create a contribution with a custom amount in kobo.
     */
    public function withAmount(int $amountInKobo): static
    {
        return $this->state(fn (array $attributes) => [
            'expected_amount' => $amountInKobo,
        ]);
    }

    // =========================================================================
    // User Association
    // =========================================================================

    /**
     * Create a contribution for a specific user.
     */
    public function forUser(User $user): static
    {
        return $this->state(fn (array $attributes) => [
            'user_id' => $user->id,
            'expected_amount' => $user->category?->monthlyAmountInKobo() ?? MemberCategory::Employed->monthlyAmountInKobo(),
        ]);
    }
}
