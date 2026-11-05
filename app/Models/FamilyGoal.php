<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\GoalMeasure;
use Database\Factories\FamilyGoalFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $family_id
 * @property int|null $created_by
 * @property string $name
 * @property GoalMeasure $measure
 * @property int $target_amount
 * @property Carbon $starts_on
 * @property Carbon $deadline
 * @property Carbon|null $archived_at
 * @property string $idempotency_key
 * @property int|null $received_amount
 * @property int|null $expense_amount
 * @property int|null $adjustment_amount
 * @property-read Family $family
 */
class FamilyGoal extends Model
{
    /** @use HasFactory<FamilyGoalFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = ['family_id', 'created_by', 'name', 'measure', 'target_amount', 'starts_on', 'deadline', 'archived_at', 'idempotency_key'];

    /** @var array<string, mixed> */
    protected $attributes = ['measure' => 'net_savings'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['measure' => GoalMeasure::class, 'target_amount' => 'integer', 'starts_on' => 'date', 'deadline' => 'date', 'archived_at' => 'datetime', 'received_amount' => 'integer', 'expense_amount' => 'integer', 'adjustment_amount' => 'integer'];
    }

    /** @return BelongsTo<Family, $this> */
    public function family(): BelongsTo
    {
        return $this->belongsTo(Family::class);
    }
}
