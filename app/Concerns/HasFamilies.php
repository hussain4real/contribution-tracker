<?php

declare(strict_types=1);

namespace App\Concerns;

use App\Enums\MemberCategory;
use App\Enums\Role;
use App\Models\Family;
use App\Models\FamilyCategory;
use App\Models\FamilyMembership;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\URL;

trait HasFamilies
{
    /**
     * @return BelongsToMany<Family, $this, FamilyMembership, 'pivot'>
     */
    public function families(): BelongsToMany
    {
        return $this->belongsToMany(Family::class, 'family_members')
            ->using(FamilyMembership::class)
            ->withPivot(['id', 'role', 'category', 'family_category_id'])
            ->withTimestamps();
    }

    /**
     * @return HasMany<FamilyMembership, $this>
     */
    public function familyMemberships(): HasMany
    {
        return $this->hasMany(FamilyMembership::class);
    }

    /**
     * @return BelongsTo<Family, $this>
     */
    public function currentFamily(): BelongsTo
    {
        return $this->belongsTo(Family::class, 'current_family_id');
    }

    public function currentFamilyMembership(): ?FamilyMembership
    {
        $familyId = $this->current_family_id ?? $this->family_id;

        if (! is_int($familyId)) {
            return null;
        }

        if ($this->relationLoaded('familyMemberships')) {
            $membership = $this->familyMemberships->first(
                fn (FamilyMembership $membership): bool => $membership->family_id === $familyId,
            );

            if ($membership instanceof FamilyMembership) {
                $membership->loadMissing('familyCategory');
            }

            return $membership;
        }

        return $this->membershipForFamilyId($familyId);
    }

    public function membershipForFamily(Family $family): ?FamilyMembership
    {
        return $this->membershipForFamilyId($family->id);
    }

    public function membershipForFamilyId(int $familyId): ?FamilyMembership
    {
        return $this->familyMemberships()
            ->with('familyCategory')
            ->where('family_id', $familyId)
            ->first();
    }

    public function belongsToFamily(Family $family): bool
    {
        if ($this->relationLoaded('familyMemberships')) {
            return $this->familyMemberships->contains(
                fn (FamilyMembership $membership): bool => $membership->family_id === $family->id,
            );
        }

        return $this->familyMemberships()
            ->where('family_id', $family->id)
            ->exists();
    }

    public function switchFamily(Family $family, ?FamilyMembership $membership = null): bool
    {
        $membership ??= $this->membershipForFamily($family);

        if (! $membership instanceof FamilyMembership) {
            return false;
        }

        $this->forceFill([
            'current_family_id' => $family->id,
            'family_id' => $family->id,
            'role' => $membership->role,
            'category' => $membership->category,
            'family_category_id' => $membership->family_category_id,
        ])->save();

        $this->setRelation('currentFamily', $family);
        $this->setRelation('family', $family);

        URL::defaults([
            'current_family' => $family->slug,
            'family' => $family->slug,
        ]);

        return true;
    }

    public function ensureFamilyMembership(
        Family $family,
        Role $role = Role::Member,
        ?MemberCategory $category = null,
        ?int $familyCategoryId = null,
    ): FamilyMembership {
        /** @var FamilyMembership $membership */
        $membership = $this->familyMemberships()->firstOrCreate(
            ['family_id' => $family->id],
            [
                'role' => $role,
                'category' => $category,
                'family_category_id' => $familyCategoryId,
            ],
        );

        return $membership;
    }

    /**
     * @return Collection<int, array{id: int, name: string, slug: string, role: 'admin'|'financial_secretary'|'member', role_label: string, category_label: string|null, is_current: bool}>
     */
    public function toUserFamilies(bool $includeCurrent = true): Collection
    {
        $currentFamilyId = $this->current_family_id ?? $this->family_id;

        $memberships = $this->relationLoaded('familyMemberships')
            ? $this->familyMemberships
            : $this->familyMemberships()
                ->with(['family:id,name,slug', 'familyCategory:id,name'])
                ->get();

        return $memberships
            ->loadMissing(['family:id,name,slug', 'familyCategory:id,name'])
            ->sortBy(fn (FamilyMembership $membership): string => strtolower($membership->family->name))
            ->filter(function (FamilyMembership $membership) use ($currentFamilyId, $includeCurrent): bool {
                $family = $membership->family;

                return $includeCurrent || $family->id !== $currentFamilyId;
            })
            ->map(function (FamilyMembership $membership) use ($currentFamilyId): array {
                $family = $membership->family;
                $categoryLabel = $membership->category?->label();

                if (
                    $membership->family_category_id !== null
                    && $membership->familyCategory instanceof FamilyCategory
                ) {
                    $categoryLabel = $membership->familyCategory->name;
                }

                return [
                    'id' => $family->id,
                    'name' => $family->name,
                    'slug' => $family->slug,
                    'role' => $membership->role->value,
                    'role_label' => $membership->role->label(),
                    'category_label' => $categoryLabel,
                    'is_current' => $family->id === $currentFamilyId,
                ];
            })
            ->values();
    }
}
