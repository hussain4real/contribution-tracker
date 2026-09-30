<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\PaymentRiskModelVersionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * @property int $id
 * @property string $version
 * @property string $schema_version
 * @property string $artifact_disk
 * @property string $artifact_path
 * @property string $artifact_sha256
 * @property list<string> $feature_schema
 * @property float $threshold
 * @property Carbon $training_window_start
 * @property Carbon $training_window_end
 * @property array<string, mixed> $dataset_summary
 * @property array<string, mixed> $metrics
 * @property bool $activation_eligible
 * @property bool $is_active
 * @property Carbon $trained_at
 * @property Carbon|null $activated_at
 * @property Carbon|null $deactivated_at
 */
class PaymentRiskModelVersion extends Model
{
    /** @use HasFactory<PaymentRiskModelVersionFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'version',
        'schema_version',
        'artifact_disk',
        'artifact_path',
        'artifact_sha256',
        'feature_schema',
        'threshold',
        'training_window_start',
        'training_window_end',
        'dataset_summary',
        'metrics',
        'activation_eligible',
        'is_active',
        'trained_at',
        'activated_at',
        'deactivated_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'feature_schema' => 'array',
            'threshold' => 'float',
            'training_window_start' => 'date',
            'training_window_end' => 'date',
            'dataset_summary' => 'array',
            'metrics' => 'array',
            'activation_eligible' => 'boolean',
            'is_active' => 'boolean',
            'trained_at' => 'datetime',
            'activated_at' => 'datetime',
            'deactivated_at' => 'datetime',
        ];
    }

    /** @return HasMany<PaymentRiskPrediction, $this> */
    public function predictions(): HasMany
    {
        return $this->hasMany(PaymentRiskPrediction::class);
    }

    protected static function booted(): void
    {
        static::updating(function (PaymentRiskModelVersion $model): void {
            $allowed = ['is_active', 'activated_at', 'deactivated_at', 'updated_at'];

            if (array_diff(array_keys($model->getDirty()), $allowed) !== []) {
                throw new LogicException('Installed payment-risk model metadata is immutable.');
            }
        });

        static::deleting(fn (): never => throw new LogicException('Installed payment-risk model versions cannot be deleted.'));
    }
}
