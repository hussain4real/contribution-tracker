<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\InvitationDeliveryMethod;
use App\Enums\Role;
use Database\Factories\FamilyInvitationFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property Carbon|null $accepted_at
 * @property Carbon|null $created_at
 * @property InvitationDeliveryMethod $delivery_method
 * @property string|null $email
 * @property Carbon $expires_at
 * @property Family|null $family
 * @property int $family_id
 * @property Role $role
 * @property string $token
 * @property User|null $inviter
 * @property string|null $whatsapp_phone
 */
class FamilyInvitation extends Model
{
    /** @use HasFactory<FamilyInvitationFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'family_id',
        'email',
        'delivery_method',
        'whatsapp_phone',
        'role',
        'token',
        'invited_by',
        'accepted_at',
        'expires_at',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'delivery_method' => InvitationDeliveryMethod::class,
            'role' => Role::class,
            'accepted_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    // =========================================================================
    // Relationships
    // =========================================================================

    /**
     * The family this invitation is for.
     *
     * @return BelongsTo<Family, $this>
     */
    public function family(): BelongsTo
    {
        return $this->belongsTo(Family::class);
    }

    /**
     * The user who sent the invitation.
     *
     * @return BelongsTo<User, $this>
     */
    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    // =========================================================================
    // Scopes
    // =========================================================================

    /**
     * Scope to pending (not accepted, not expired) invitations.
     *
     * @param  Builder<FamilyInvitation>  $query
     * @return Builder<FamilyInvitation>
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->whereNull('accepted_at')
            ->where('expires_at', '>', now());
    }

    /**
     * Scope to expired invitations.
     *
     * @param  Builder<FamilyInvitation>  $query
     * @return Builder<FamilyInvitation>
     */
    public function scopeExpired(Builder $query): Builder
    {
        return $query->where('expires_at', '<=', now())
            ->whereNull('accepted_at');
    }

    // =========================================================================
    // Helper Methods
    // =========================================================================

    /**
     * Check if this invitation has been accepted.
     */
    public function isAccepted(): bool
    {
        return $this->accepted_at !== null;
    }

    /**
     * Check if this invitation has expired.
     */
    public function isExpired(): bool
    {
        return $this->expires_at->isPast() && ! $this->isAccepted();
    }

    /**
     * Check if this invitation is still pending.
     */
    public function isPending(): bool
    {
        return ! $this->isAccepted() && ! $this->isExpired();
    }

    /**
     * Get the contact value used for this invitation.
     */
    public function contact(): string
    {
        return $this->delivery_method === InvitationDeliveryMethod::WhatsApp
            ? (string) $this->whatsapp_phone
            : (string) $this->email;
    }
}
