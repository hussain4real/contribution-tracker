<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\MemberCategory;
use App\Enums\Role;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $family_id
 * @property int $user_id
 * @property Role $role
 * @property MemberCategory|null $category
 * @property int|null $family_category_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Family $family
 * @property-read User $user
 * @property-read FamilyCategory|null $familyCategory
 */
#[Fillable(['family_id', 'user_id', 'role', 'category', 'family_category_id'])]
class FamilyMembership extends Pivot
{
    protected $table = 'family_members';

    public $incrementing = true;

    /**
     * @return BelongsTo<Family, $this>
     */
    public function family(): BelongsTo
    {
        return $this->belongsTo(Family::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<FamilyCategory, $this>
     */
    public function familyCategory(): BelongsTo
    {
        return $this->belongsTo(FamilyCategory::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => Role::class,
            'category' => MemberCategory::class,
        ];
    }
}
