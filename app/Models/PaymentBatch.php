<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PaymentMethod;
use App\Enums\PaymentSource;
use Database\Factories\PaymentBatchFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * @property int $id
 * @property int $family_id
 * @property int|null $family_membership_id
 * @property string $member_name
 * @property int $total_amount
 * @property Carbon $paid_at
 * @property PaymentMethod $method
 * @property PaymentSource $source
 * @property string|null $reference
 * @property int|null $recorded_by
 * @property string|null $notes
 * @property int $receipt_number
 * @property string $idempotency_key
 * @property-read Family $family
 * @property-read FamilyMembership|null $membership
 * @property-read User|null $recorder
 * @property-read Collection<int, Payment> $allocations
 * @property-read FinancialReversal|null $reversal
 */
class PaymentBatch extends Model
{
    /** @use HasFactory<PaymentBatchFactory> */
    use HasFactory;

    public const MORPH_TYPE = 'payment_batch';

    /** @var list<string> */
    protected $fillable = [
        'family_id',
        'family_membership_id',
        'member_name',
        'total_amount',
        'paid_at',
        'method',
        'source',
        'reference',
        'recorded_by',
        'notes',
        'receipt_number',
        'idempotency_key',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'total_amount' => 'integer',
            'paid_at' => 'date',
            'method' => PaymentMethod::class,
            'source' => PaymentSource::class,
            'receipt_number' => 'integer',
        ];
    }

    /** @return BelongsTo<Family, $this> */
    public function family(): BelongsTo
    {
        return $this->belongsTo(Family::class);
    }

    /** @return BelongsTo<FamilyMembership, $this> */
    public function membership(): BelongsTo
    {
        return $this->belongsTo(FamilyMembership::class, 'family_membership_id');
    }

    /** @return BelongsTo<User, $this> */
    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    /** @return HasMany<Payment, $this> */
    public function allocations(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /** @return MorphOne<FinancialReversal, $this> */
    public function reversal(): MorphOne
    {
        return $this->morphOne(FinancialReversal::class, 'reversible');
    }

    /**
     * @param  Builder<PaymentBatch>  $query
     * @return Builder<PaymentBatch>
     */
    public function scopeEffective(Builder $query): Builder
    {
        return $query->whereDoesntHave('reversal');
    }

    public function isReversed(): bool
    {
        return $this->relationLoaded('reversal')
            ? $this->reversal instanceof FinancialReversal
            : $this->reversal()->exists();
    }

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new LogicException('Posted payment batches are immutable.'));
        static::deleting(fn (): never => throw new LogicException('Posted payment batches cannot be deleted.'));
    }
}
