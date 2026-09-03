<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\PaymentRiskAdvisoryBand;
use App\Enums\PaymentRiskHistoryTier;
use App\Models\Contribution;
use App\Models\Family;
use App\Models\FamilyMembership;
use App\Models\PaymentRiskModelVersion;
use App\Models\PaymentRiskPrediction;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use LogicException;

/** @extends Factory<PaymentRiskPrediction> */
class PaymentRiskPredictionFactory extends Factory
{
    /** @return array<model-property<PaymentRiskPrediction>, mixed> */
    public function definition(): array
    {
        return [
            'family_id' => Family::factory(),
            'family_membership_id' => function (array $attributes): int {
                $familyId = $this->integerAttribute($attributes, 'family_id');
                $user = User::factory()->member()->create(['family_id' => $familyId]);

                return FamilyMembership::query()
                    ->where('family_id', $familyId)
                    ->where('user_id', $user->id)
                    ->firstOrFail()
                    ->id;
            },
            'contribution_id' => function (array $attributes): int {
                $familyId = $this->integerAttribute($attributes, 'family_id');
                $membershipId = $this->integerAttribute($attributes, 'family_membership_id');
                $membership = FamilyMembership::query()->findOrFail($membershipId);

                return Contribution::factory()->create([
                    'family_id' => $familyId,
                    'user_id' => $membership->user_id,
                    'due_date' => now()->addDays(14),
                ])->id;
            },
            'payment_risk_model_version_id' => PaymentRiskModelVersion::factory(),
            'cutoff_at' => now()->startOfSecond(),
            'probability' => 0.61,
            'advisory_band' => PaymentRiskAdvisoryBand::PriorityReview,
            'history_tier' => PaymentRiskHistoryTier::Standard,
            'history_periods' => 12,
            'feature_snapshot_hash' => hash('sha256', 'factory-features'),
            'factors' => ['Prior overdue streak increased review priority.'],
            'generated_at' => now(),
        ];
    }

    /** @param array<array-key, mixed> $attributes */
    private function integerAttribute(array $attributes, string $key): int
    {
        $value = $attributes[$key] ?? null;

        if (! is_int($value)) {
            throw new LogicException("The payment-risk prediction factory attribute [{$key}] must be an integer.");
        }

        return $value;
    }
}
