<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ReconciliationPeriodStatus;
use Database\Factories\ReconciliationPeriodFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * @property int $id
 * @property int $family_id
 * @property Carbon $starts_at
 * @property Carbon $ends_at
 * @property ReconciliationPeriodStatus $status
 * @property int|null $opening_balance
 * @property int|null $closing_balance
 * @property int|null $bank_net
 * @property int|null $ledger_net
 * @property int|null $variance
 * @property Carbon|null $closed_at
 * @property int|null $closed_by
 * @property Carbon|null $reopened_at
 * @property int|null $reopened_by
 * @property string|null $reopen_reason
 */
class ReconciliationPeriod extends Model
{
    /** @use HasFactory<ReconciliationPeriodFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'family_id', 'starts_at', 'ends_at', 'status', 'opening_balance', 'closing_balance',
        'bank_net', 'ledger_net', 'variance', 'closed_at', 'closed_by', 'reopened_at',
        'reopened_by', 'reopen_reason',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'starts_at' => 'date',
            'ends_at' => 'date',
            'status' => ReconciliationPeriodStatus::class,
            'opening_balance' => 'integer',
            'closing_balance' => 'integer',
            'bank_net' => 'integer',
            'ledger_net' => 'integer',
            'variance' => 'integer',
            'closed_at' => 'datetime',
            'reopened_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Family, $this> */
    public function family(): BelongsTo
    {
        return $this->belongsTo(Family::class);
    }

    /** @return BelongsTo<User, $this> */
    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    /** @return BelongsTo<User, $this> */
    public function reopener(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reopened_by');
    }

    public function isLocked(): bool
    {
        return $this->status === ReconciliationPeriodStatus::Closed;
    }

    protected static function booted(): void
    {
        static::updating(function (ReconciliationPeriod $period): void {
            if ($period->getRawOriginal('status') !== ReconciliationPeriodStatus::Closed->value) {
                return;
            }

            $allowed = ['status', 'reopened_at', 'reopened_by', 'reopen_reason', 'updated_at'];

            if (array_diff(array_keys($period->getDirty()), $allowed) !== []) {
                throw new LogicException('Closed reconciliation snapshots are immutable.');
            }
        });
        static::deleting(function (ReconciliationPeriod $period): void {
            if ($period->isLocked()) {
                throw new LogicException('Closed reconciliation periods cannot be deleted.');
            }
        });
    }
}
