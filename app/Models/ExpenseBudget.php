<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ExpenseBudgetFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $family_id
 * @property int $expense_category_id
 * @property string $month
 * @property int $amount
 */
class ExpenseBudget extends Model
{
    /** @use HasFactory<ExpenseBudgetFactory> */
    use HasFactory;

    protected $fillable = ['family_id', 'expense_category_id', 'month', 'amount'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['amount' => 'integer'];
    }

    /** @return BelongsTo<Family, $this> */
    public function family(): BelongsTo
    {
        return $this->belongsTo(Family::class);
    }
}
