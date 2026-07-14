<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\FinancialReversalFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * @property int $id
 * @property int $family_id
 * @property string $reversible_type
 * @property int $reversible_id
 * @property string|null $replacement_type
 * @property int|null $replacement_id
 * @property string $reason
 * @property int|null $reversed_by
 * @property string $request_id
 * @property Carbon $created_at
 * @property-read Model $reversible
 * @property-read Model|null $replacement
 * @property-read User|null $reverser
 */
class FinancialReversal extends Model
{
    /** @use HasFactory<FinancialReversalFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = [
        'family_id',
        'reversible_type',
        'reversible_id',
        'replacement_type',
        'replacement_id',
        'reason',
        'reversed_by',
        'request_id',
    ];

    /** @return BelongsTo<Family, $this> */
    public function family(): BelongsTo
    {
        return $this->belongsTo(Family::class);
    }

    /** @return BelongsTo<User, $this> */
    public function reverser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reversed_by');
    }

    /** @return MorphTo<Model, $this> */
    public function reversible(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return MorphTo<Model, $this> */
    public function replacement(): MorphTo
    {
        return $this->morphTo();
    }

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new LogicException('Financial reversals are append-only.'));
        static::deleting(fn (): never => throw new LogicException('Financial reversals cannot be deleted.'));
    }
}
