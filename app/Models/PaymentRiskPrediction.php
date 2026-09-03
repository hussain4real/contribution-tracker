<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PaymentRiskAdvisoryBand;
use App\Enums\PaymentRiskHistoryTier;
use Database\Factories\PaymentRiskPredictionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * @property int $id
 * @property int $family_id
 * @property int $family_membership_id
 * @property int $contribution_id
 * @property int $payment_risk_model_version_id
 * @property Carbon $cutoff_at
 * @property float|null $probability
 * @property PaymentRiskAdvisoryBand|null $advisory_band
 * @property PaymentRiskHistoryTier $history_tier
 * @property int $history_periods
 * @property string $feature_snapshot_hash
 * @property list<string> $factors
 * @property Carbon $generated_at
 */
class PaymentRiskPrediction extends Model
{
    /** @use HasFactory<PaymentRiskPredictionFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'family_id',
        'family_membership_id',
        'contribution_id',
        'payment_risk_model_version_id',
        'cutoff_at',
        'probability',
        'advisory_band',
        'history_tier',
        'history_periods',
        'feature_snapshot_hash',
        'factors',
        'generated_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'cutoff_at' => 'datetime',
            'probability' => 'float',
            'advisory_band' => PaymentRiskAdvisoryBand::class,
            'history_tier' => PaymentRiskHistoryTier::class,
            'history_periods' => 'integer',
            'factors' => 'array',
            'generated_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Family, $this> */
    public function family(): BelongsTo
    {
        return $this->belongsTo(Family::class);
    }

    /** @return BelongsTo<FamilyMembership, $this> */
    public function membership(): BelongsTo
    {
        return $this->belongsTo(FamilyMembership::class, 'family_membership_id');
    }

    /** @return BelongsTo<Contribution, $this> */
    public function contribution(): BelongsTo
    {
        return $this->belongsTo(Contribution::class);
    }

    /** @return BelongsTo<PaymentRiskModelVersion, $this> */
    public function modelVersion(): BelongsTo
    {
        return $this->belongsTo(PaymentRiskModelVersion::class, 'payment_risk_model_version_id');
    }

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new LogicException('Payment-risk predictions are immutable.'));
        static::deleting(fn (): never => throw new LogicException('Payment-risk predictions cannot be deleted.'));
    }
}
