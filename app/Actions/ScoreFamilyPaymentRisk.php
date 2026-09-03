<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\PaymentRiskAdvisoryBand;
use App\Enums\PaymentRiskHistoryTier;
use App\Models\Contribution;
use App\Models\Family;
use App\Models\PaymentRiskPrediction;
use App\Services\HistoricalPaymentRiskFeatureBuilder;
use App\Services\LogisticPaymentRiskScorer;
use App\Services\PaymentRiskModelRepository;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;
use JsonException;

final class ScoreFamilyPaymentRisk
{
    public function __construct(
        private readonly HistoricalPaymentRiskFeatureBuilder $featureBuilder,
        private readonly LogisticPaymentRiskScorer $scorer,
        private readonly PaymentRiskModelRepository $models,
    ) {}

    /**
     * @return array{created: int, existing: int, unavailable: int, pooled: int, scored: int}
     *
     * @throws JsonException
     */
    public function handle(Family $family, int $year, int $month): array
    {
        $loaded = $this->models->active();
        $model = $loaded['model'];
        $artifact = $loaded['artifact'];
        /** @var EloquentCollection<int, Contribution> $targets */
        $targets = Contribution::query()
            ->where('family_id', $family->id)
            ->forMonth($year, $month)
            ->whereNotNull('due_date')
            ->whereNotNull('created_at')
            ->orderBy('id')
            ->get();
        $snapshots = $this->featureBuilder->buildForFamily($family, $targets);
        $counts = ['created' => 0, 'existing' => 0, 'unavailable' => 0, 'pooled' => 0, 'scored' => 0];
        $prevalence = data_get($artifact, 'training.overdue_prevalence');
        $prevalence = is_numeric($prevalence) ? (float) $prevalence : 0.0;
        $existingIdentities = PaymentRiskPrediction::query()
            ->where('payment_risk_model_version_id', $model->id)
            ->whereIn('contribution_id', $targets->pluck('id'))
            ->get(['contribution_id', 'cutoff_at'])
            ->mapWithKeys(fn (PaymentRiskPrediction $prediction): array => [
                $prediction->contribution_id.'|'.$prediction->cutoff_at->toDateTimeString() => true,
            ]);
        $rows = [];
        $generatedAt = now();

        foreach ($targets as $target) {
            $snapshot = $snapshots[$target->id];
            $cutoff = $snapshot['cutoff_at']->startOfSecond();

            $identity = $target->id.'|'.$cutoff->toDateTimeString();

            if ($existingIdentities->has($identity)) {
                $counts['existing']++;

                continue;
            }

            $historyPeriods = $snapshot['history_periods'];
            $historyTier = PaymentRiskHistoryTier::forHistoryCount($historyPeriods);
            $features = $snapshot['features'];
            $probability = null;
            $band = null;
            $factors = [];

            if (! $snapshot['timing_eligible']) {
                $historyTier = PaymentRiskHistoryTier::Unavailable;
                $factors = ['The contribution was generated fewer than seven days before its due date.'];
                $counts['unavailable']++;
            } elseif ($historyTier === PaymentRiskHistoryTier::Unavailable) {
                $factors = ['Fewer than three mature periods were recorded before the scoring cutoff.'];
                $counts['unavailable']++;
            } elseif ($historyTier === PaymentRiskHistoryTier::Pooled) {
                $band = $prevalence >= $model->threshold
                    ? PaymentRiskAdvisoryBand::PriorityReview
                    : PaymentRiskAdvisoryBand::RoutineReview;
                $factors = ['The training-set prevalence was used because only three to five mature periods were available.'];
                $counts['pooled']++;
            } else {
                $score = $this->scorer->score($artifact, $features);
                $probability = $score['probability'];
                $band = $score['advisory_band'];
                $factors = $score['factors'];
                $counts['scored']++;
            }

            $featureJson = json_encode(
                $features,
                JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR,
            );

            $rows[] = [
                'family_id' => $family->id,
                'family_membership_id' => $snapshot['membership_id'],
                'contribution_id' => $target->id,
                'payment_risk_model_version_id' => $model->id,
                'cutoff_at' => $cutoff,
                'probability' => $probability,
                'advisory_band' => $band?->value,
                'history_tier' => $historyTier->value,
                'history_periods' => $historyPeriods,
                'feature_snapshot_hash' => hash('sha256', $featureJson),
                'factors' => json_encode($factors, JSON_THROW_ON_ERROR),
                'generated_at' => $generatedAt,
                'created_at' => $generatedAt,
                'updated_at' => $generatedAt,
            ];
        }

        if ($rows !== []) {
            $counts['created'] = DB::transaction(
                fn (): int => DB::table('payment_risk_predictions')->insertOrIgnore($rows),
                3,
            );
            $counts['existing'] += count($rows) - $counts['created'];
        }

        return $counts;
    }
}
