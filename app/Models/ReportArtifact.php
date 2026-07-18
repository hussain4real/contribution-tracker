<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ReportFormat;
use App\Enums\ReportType;
use Database\Factories\ReportArtifactFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $uuid
 * @property int $family_id
 * @property int|null $requested_by
 * @property ReportType $type
 * @property ReportFormat $format
 * @property array<string, mixed> $filters
 * @property string $filter_hash
 * @property string $disk
 * @property string $path
 * @property string $filename
 * @property string $mime_type
 * @property int $size
 * @property Carbon|null $expires_at
 * @property-read Family $family
 * @property-read User|null $requester
 */
class ReportArtifact extends Model
{
    /** @use HasFactory<ReportArtifactFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'uuid', 'family_id', 'requested_by', 'type', 'format', 'filters',
        'filter_hash', 'disk', 'path', 'filename', 'mime_type', 'size', 'expires_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => ReportType::class,
            'format' => ReportFormat::class,
            'filters' => 'array',
            'size' => 'integer',
            'expires_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /** @return BelongsTo<Family, $this> */
    public function family(): BelongsTo
    {
        return $this->belongsTo(Family::class);
    }

    /** @return BelongsTo<User, $this> */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /** @return HasMany<ReportDelivery, $this> */
    public function deliveries(): HasMany
    {
        return $this->hasMany(ReportDelivery::class);
    }
}
