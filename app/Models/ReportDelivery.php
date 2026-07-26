<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ReportDeliveryStatus;
use Database\Factories\ReportDeliveryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $uuid
 * @property int $report_schedule_id
 * @property int|null $report_artifact_id
 * @property Carbon $period_start
 * @property Carbon $period_end
 * @property string $channel
 * @property string $recipient
 * @property ReportDeliveryStatus $status
 * @property string $idempotency_key
 * @property Carbon|null $attempted_at
 * @property Carbon|null $sent_at
 * @property Carbon|null $failed_at
 * @property string|null $error
 * @property-read ReportSchedule $schedule
 * @property-read ReportArtifact|null $artifact
 */
class ReportDelivery extends Model
{
    /** @use HasFactory<ReportDeliveryFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'uuid', 'report_schedule_id', 'report_artifact_id', 'period_start', 'period_end',
        'channel', 'recipient', 'status', 'idempotency_key', 'attempted_at',
        'sent_at', 'failed_at', 'error',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'status' => ReportDeliveryStatus::class,
            'attempted_at' => 'datetime',
            'sent_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /** @return BelongsTo<ReportSchedule, $this> */
    public function schedule(): BelongsTo
    {
        return $this->belongsTo(ReportSchedule::class, 'report_schedule_id');
    }

    /** @return BelongsTo<ReportArtifact, $this> */
    public function artifact(): BelongsTo
    {
        return $this->belongsTo(ReportArtifact::class, 'report_artifact_id');
    }
}
