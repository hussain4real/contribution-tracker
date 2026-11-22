<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ExpenseTemplateFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $family_id
 * @property int|null $expense_category_id
 * @property int $amount
 * @property string $description
 * @property int $day_of_month
 * @property string|null $next_run_on
 * @property string $idempotency_key
 * @property-read ExpenseCategory|null $category
 */
class ExpenseTemplate extends Model
{
    /** @use HasFactory<ExpenseTemplateFactory> */
    use HasFactory;

    protected $fillable = ['family_id', 'expense_category_id', 'amount', 'description', 'day_of_month', 'next_run_on', 'idempotency_key'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['amount' => 'integer', 'day_of_month' => 'integer'];
    }

    /** @return BelongsTo<ExpenseCategory, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'expense_category_id');
    }
}
