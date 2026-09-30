<?php

declare(strict_types=1);

use App\Enums\PaymentRiskAdvisoryBand;
use App\Enums\PaymentRiskHistoryTier;
use App\Models\PaymentRiskModelVersion;
use App\Models\PaymentRiskPrediction;

it('exposes payment-risk enum labels warnings and exact history boundaries', function () {
    expect(PaymentRiskAdvisoryBand::RoutineReview->label())->toBe('Routine review')
        ->and(PaymentRiskAdvisoryBand::PriorityReview->label())->toBe('Priority review')
        ->and(PaymentRiskHistoryTier::forHistoryCount(2))->toBe(PaymentRiskHistoryTier::Unavailable)
        ->and(PaymentRiskHistoryTier::forHistoryCount(3))->toBe(PaymentRiskHistoryTier::Pooled)
        ->and(PaymentRiskHistoryTier::forHistoryCount(6))->toBe(PaymentRiskHistoryTier::Experimental)
        ->and(PaymentRiskHistoryTier::forHistoryCount(12))->toBe(PaymentRiskHistoryTier::Standard)
        ->and(PaymentRiskHistoryTier::Unavailable->label())->toBe('Insufficient history')
        ->and(PaymentRiskHistoryTier::Pooled->label())->toBe('Pooled baseline')
        ->and(PaymentRiskHistoryTier::Experimental->label())->toBe('Experimental')
        ->and(PaymentRiskHistoryTier::Standard->label())->toBe('Standard advisory')
        ->and(PaymentRiskHistoryTier::Unavailable->warning())->toContain('No score')
        ->and(PaymentRiskHistoryTier::Pooled->warning())->toContain('portfolio baseline')
        ->and(PaymentRiskHistoryTier::Experimental->warning())->toContain('experimental')
        ->and(PaymentRiskHistoryTier::Standard->warning())->toBeNull();
});

it('exposes tenant-scoped prediction relations and guards immutable deletion', function () {
    $prediction = PaymentRiskPrediction::factory()->create();
    $family = $prediction->family()->firstOrFail();
    $membership = $prediction->membership()->firstOrFail();
    $contribution = $prediction->contribution()->firstOrFail();
    $model = $prediction->modelVersion()->firstOrFail();

    expect($family->id)->toBe($prediction->family_id)
        ->and($membership->id)->toBe($prediction->family_membership_id)
        ->and($contribution->id)->toBe($prediction->contribution_id)
        ->and($family->paymentRiskPredictions()->whereKey($prediction->id)->exists())->toBeTrue()
        ->and($membership->paymentRiskPredictions()->whereKey($prediction->id)->exists())->toBeTrue()
        ->and($contribution->paymentRiskPredictions()->whereKey($prediction->id)->exists())->toBeTrue()
        ->and($model->predictions()->whereKey($prediction->id)->exists())->toBeTrue()
        ->and(fn () => $prediction->delete())->toThrow(LogicException::class)
        ->and(fn () => $model->delete())->toThrow(LogicException::class);
});

it('permits only activation lifecycle fields to change on installed models', function () {
    $model = PaymentRiskModelVersion::factory()->create();

    expect($model->update([
        'is_active' => true,
        'activated_at' => now(),
    ]))->toBeTrue()
        ->and($model->refresh()->is_active)->toBeTrue();
});
