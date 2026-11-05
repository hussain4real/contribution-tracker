<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * @property int $id
 * @property int $family_id
 * @property int $expense_request_id
 * @property int $user_id
 * @property string $decision
 * @property string|null $reason
 * @property-read User|null $user
 */
class ExpenseApproval extends Model
{
    protected $fillable = ['family_id', 'expense_request_id', 'user_id', 'decision', 'reason'];

    /** @return BelongsTo<User,$this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new LogicException('Expense decisions are immutable.'));
        static::deleting(fn (): never => throw new LogicException('Expense decisions are immutable.'));
    }
}
