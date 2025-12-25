<?php

namespace Database\Factories;

use App\Enums\MemberCategory;
use App\Models\Contribution;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Payment>
 */
class PaymentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'contribution_id' => Contribution::factory(),
            'amount' => MemberCategory::Employed->monthlyAmountInKobo(),
            'paid_at' => now(),
            'recorded_by' => User::factory()->financialSecretary(),
            'notes' => null,
        ];
    }

    // =========================================================================
    // Amount States
    // =========================================================================

    /**
     * Create a partial payment (half of employed amount).
     */
    public function partial(): static
    {
        return $this->state(fn (array $attributes) => [
            'amount' => (int) (MemberCategory::Employed->monthlyAmountInKobo() / 2),
        ]);
    }

    /**
     * Create a full payment matching contribution expected amount.
     */
    public function full(): static
    {
        return $this->state(function (array $attributes) {
            // If contribution_id is set and it's a Contribution instance, use its expected amount
            if (isset($attributes['contribution_id']) && $attributes['contribution_id'] instanceof Contribution) {
                return ['amount' => $attributes['contribution_id']->expected_amount];
            }

            return ['amount' => MemberCategory::Employed->monthlyAmountInKobo()];
        });
    }

    /**
     * Create a payment with a specific amount in kobo.
     */
    public function withAmount(int $amountInKobo): static
    {
        return $this->state(fn (array $attributes) => [
            'amount' => $amountInKobo,
        ]);
    }

    // =========================================================================
    // Association States
    // =========================================================================

    /**
     * Create a payment for a specific contribution.
     */
    public function forContribution(Contribution $contribution): static
    {
        return $this->state(fn (array $attributes) => [
            'contribution_id' => $contribution->id,
            'amount' => $contribution->expected_amount,
        ]);
    }

    /**
     * Create a payment recorded by a specific user.
     */
    public function recordedBy(User $user): static
    {
        return $this->state(fn (array $attributes) => [
            'recorded_by' => $user->id,
        ]);
    }

    // =========================================================================
    // Date States
    // =========================================================================

    /**
     * Create a payment made today.
     */
    public function today(): static
    {
        return $this->state(fn (array $attributes) => [
            'paid_at' => now(),
        ]);
    }

    /**
     * Create a payment made on a specific date.
     */
    public function paidOn(string|\DateTimeInterface $date): static
    {
        return $this->state(fn (array $attributes) => [
            'paid_at' => $date,
        ]);
    }

    // =========================================================================
    // Notes States
    // =========================================================================

    /**
     * Create a payment with notes.
     */
    public function withNotes(string $notes): static
    {
        return $this->state(fn (array $attributes) => [
            'notes' => $notes,
        ]);
    }
}
