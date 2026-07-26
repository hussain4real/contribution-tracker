<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ReportFormat;
use App\Enums\ReportScheduleFrequency;
use App\Enums\ReportType;
use Database\Factories\ReportScheduleFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $family_id
 * @property int|null $created_by
 * @property string $name
 * @property ReportType $report_type
 * @property ReportFormat $format
 * @property array<string, mixed> $filters
 * @property list<string> $channels
 * @property list<string> $recipients
 * @property ReportScheduleFrequency $frequency
 * @property string $timezone
 * @property Carbon $next_run_at
 * @property Carbon|null $last_run_at
 * @property bool $is_active
 * @property int $deliveries_count
 * @property-read Family $family
 */
class ReportSchedule extends Model
{
    /** @use HasFactory<ReportScheduleFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'family_id', 'created_by', 'name', 'report_type', 'format', 'filters',
        'channels', 'recipients', 'frequency', 'timezone', 'next_run_at',
        'last_run_at', 'is_active',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'report_type' => ReportType::class,
            'format' => ReportFormat::class,
            'filters' => 'array',
            'channels' => 'array',
            'recipients' => 'array',
            'frequency' => ReportScheduleFrequency::class,
            'next_run_at' => 'datetime',
            'last_run_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<Family, $this> */
    public function family(): BelongsTo
    {
        return $this->belongsTo(Family::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return HasMany<ReportDelivery, $this> */
    public function deliveries(): HasMany
    {
        return $this->hasMany(ReportDelivery::class);
    }
}
