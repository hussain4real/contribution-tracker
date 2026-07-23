<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ReconciliationImportStatus;
use Database\Factories\ReconciliationImportFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $family_id
 * @property int|null $uploaded_by
 * @property string $disk
 * @property string $path
 * @property string $original_name
 * @property string $mime_type
 * @property int $size
 * @property string $file_fingerprint
 * @property string $delimiter
 * @property list<string> $headers
 * @property list<array<string, string|null>> $preview_rows
 * @property array<string, string|null>|null $mapping
 * @property ReconciliationImportStatus $status
 * @property int $row_count
 * @property int $imported_count
 * @property int $duplicate_count
 * @property Carbon|null $imported_at
 * @property Carbon|null $failed_at
 * @property string|null $error
 * @property-read Family $family
 * @property-read User|null $uploader
 * @property-read Collection<int, BankTransaction> $transactions
 */
class ReconciliationImport extends Model
{
    /** @use HasFactory<ReconciliationImportFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'family_id', 'uploaded_by', 'disk', 'path', 'original_name', 'mime_type', 'size',
        'file_fingerprint', 'delimiter', 'headers', 'preview_rows', 'mapping', 'status',
        'row_count', 'imported_count', 'duplicate_count', 'imported_at', 'failed_at', 'error',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'headers' => 'array',
            'preview_rows' => 'array',
            'mapping' => 'array',
            'status' => ReconciliationImportStatus::class,
            'row_count' => 'integer',
            'imported_count' => 'integer',
            'duplicate_count' => 'integer',
            'imported_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Family, $this> */
    public function family(): BelongsTo
    {
        return $this->belongsTo(Family::class);
    }

    /** @return BelongsTo<User, $this> */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /** @return HasMany<BankTransaction, $this> */
    public function transactions(): HasMany
    {
        return $this->hasMany(BankTransaction::class);
    }
}
