<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ExpenseRequestFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $family_id
 * @property int|null $requested_by
 * @property int $amount
 * @property string $description
 * @property Carbon $spent_at
 * @property string $status
 * @property int $required_approvals
 * @property string $idempotency_key
 * @property-read User|null $requester
 * @property-read Expense|null $expense
 * @property-read Collection<int, ExpenseApproval> $approvals
 * @property-read Collection<int, ExpenseDocument> $documents
 */
class ExpenseRequest extends Model
{
    /** @use HasFactory<ExpenseRequestFactory> */
    use HasFactory;

    protected $fillable = ['family_id', 'requested_by', 'amount', 'description', 'spent_at', 'status', 'required_approvals', 'idempotency_key'];

    protected $attributes = ['status' => 'submitted'];

    /** @return array<string,string> */
    protected function casts(): array
    {
        return ['amount' => 'integer', 'spent_at' => 'date', 'required_approvals' => 'integer'];
    }

    /** @return BelongsTo<User,$this> */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /** @return HasMany<ExpenseApproval,$this> */
    public function approvals(): HasMany
    {
        return $this->hasMany(ExpenseApproval::class)->orderBy('id');
    }

    /** @return HasMany<ExpenseDocument,$this> */
    public function documents(): HasMany
    {
        return $this->hasMany(ExpenseDocument::class);
    }

    /** @return HasOne<Expense,$this> */
    public function expense(): HasOne
    {
        return $this->hasOne(Expense::class);
    }
}
