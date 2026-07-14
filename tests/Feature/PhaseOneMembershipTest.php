<?php

declare(strict_types=1);

use App\Actions\AssignFamilyCategory;
use App\Enums\MemberCategory;
use App\Enums\Role;
use App\Models\Contribution;
use App\Models\Family;
use App\Models\FamilyCategory;
use App\Models\FamilyMembership;
use App\Models\FamilyMembershipCategoryAssignment;
use App\Models\User;
use Illuminate\Support\Facades\Date;

afterEach(function (): void {
    Date::setTestNow();
});

it('isolates role category display name and lifecycle per family membership', function () {
    $firstFamily = Family::factory()->create();
    $secondFamily = Family::factory()->create();
    $firstCategory = FamilyCategory::factory()->create([
        'family_id' => $firstFamily->id,
        'name' => 'Adults',
        'slug' => 'adults',
        'monthly_amount' => 4000,
    ]);
    $secondCategory = FamilyCategory::factory()->create([
        'family_id' => $secondFamily->id,
        'name' => 'Students',
        'slug' => 'students',
        'monthly_amount' => 1000,
    ]);
    $user = User::factory()->member()->create([
        'family_id' => $firstFamily->id,
        'current_family_id' => $firstFamily->id,
        'family_category_id' => $firstCategory->id,
    ]);
    $firstMembership = FamilyMembership::query()
        ->where('family_id', $firstFamily->id)
        ->where('user_id', $user->id)
        ->firstOrFail();
    $secondMembership = $user->ensureFamilyMembership(
        $secondFamily,
        Role::FinancialSecretary,
        familyCategoryId: $secondCategory->id,
    );

    $firstMembership->forceFill(['display_name' => 'Alex at Home'])->save();
    $secondMembership->forceFill(['display_name' => 'Treasurer Alex'])->save();
    $firstMembership->forceFill([
        'archived_at' => now(),
        'archived_by' => $user->id,
        'archive_reason' => 'Left this family only.',
    ])->save();

    expect($user->refresh()->archived_at)->toBeNull()
        ->and($user->belongsToFamily($firstFamily))->toBeFalse()
        ->and($user->belongsToFamilyIncludingArchived($firstFamily))->toBeTrue()
        ->and($user->belongsToFamily($secondFamily))->toBeTrue()
        ->and($user->switchFamily($secondFamily))->toBeTrue()
        ->and($user->activeRole())->toBe(Role::FinancialSecretary)
        ->and($user->currentFamilyMembership()?->displayName())->toBe('Treasurer Alex')
        ->and($user->currentFamilyMembership()?->family_category_id)->toBe($secondCategory->id);
});

it('resolves dated category history and snapshots contributions permanently', function () {
    Date::setTestNow('2026-07-13 10:00:00');

    $family = Family::factory()->create();
    $adult = FamilyCategory::factory()->create([
        'family_id' => $family->id,
        'name' => 'Adult',
        'slug' => 'adult',
        'monthly_amount' => 4000,
    ]);
    $student = FamilyCategory::factory()->create([
        'family_id' => $family->id,
        'name' => 'Student',
        'slug' => 'student',
        'monthly_amount' => 1000,
    ]);
    $member = User::factory()->member()->create([
        'family_id' => $family->id,
        'family_category_id' => $adult->id,
    ]);
    $membership = FamilyMembership::query()
        ->where('family_id', $family->id)
        ->where('user_id', $member->id)
        ->firstOrFail();

    app(AssignFamilyCategory::class)->handle($membership, $student, $member);

    expect($membership->categoryForPeriod(2026, 7)?->id)->toBe($adult->id)
        ->and($membership->categoryForPeriod(2026, 8)?->id)->toBe($student->id)
        ->and(FamilyMembershipCategoryAssignment::query()->where('family_membership_id', $membership->id)->count())->toBe(2);

    $this->artisan('contributions:generate', ['--family' => $family->id, '--year' => 2026, '--month' => 7])
        ->assertSuccessful();
    $this->artisan('contributions:generate', ['--family' => $family->id, '--year' => 2026, '--month' => 8])
        ->assertSuccessful();

    $july = Contribution::query()->where('family_id', $family->id)->forMonth(2026, 7)->firstOrFail();
    $august = Contribution::query()->where('family_id', $family->id)->forMonth(2026, 8)->firstOrFail();

    expect($july->category_name)->toBe('Adult')
        ->and($july->category_amount)->toBe(4000)
        ->and($july->expected_amount)->toBe(4000)
        ->and($august->category_name)->toBe('Student')
        ->and($august->category_amount)->toBe(1000)
        ->and($august->expected_amount)->toBe(1000);

    $adult->update(['name' => 'Working Adult', 'monthly_amount' => 9000]);

    expect($july->refresh()->category_name)->toBe('Adult')
        ->and($july->category_amount)->toBe(4000)
        ->and($july->expected_amount)->toBe(4000);
});

it('lets family administrators change membership attributes without changing global identity', function () {
    $family = Family::factory()->create();
    $adult = FamilyCategory::factory()->create([
        'family_id' => $family->id,
        'name' => 'Adult',
        'slug' => 'adult',
        'monthly_amount' => 4000,
    ]);
    $student = FamilyCategory::factory()->create([
        'family_id' => $family->id,
        'name' => 'Student',
        'slug' => 'student',
        'monthly_amount' => 1000,
    ]);
    $admin = User::factory()->admin()->create(['family_id' => $family->id]);
    $member = User::factory()->member()->create([
        'name' => 'Global Name',
        'email' => 'global@example.com',
        'family_id' => $family->id,
        'family_category_id' => $adult->id,
    ]);

    $this->actingAs($admin)
        ->put("/members/{$member->id}", [
            'display_name' => 'Family Nickname',
            'family_category_id' => $student->id,
            'role' => Role::FinancialSecretary->value,
            'effective_immediately' => true,
        ])
        ->assertRedirect();

    $membership = $member->membershipForFamily($family);

    expect($member->refresh()->name)->toBe('Global Name')
        ->and($member->email)->toBe('global@example.com')
        ->and($membership?->display_name)->toBe('Family Nickname')
        ->and($membership?->role)->toBe(Role::FinancialSecretary)
        ->and($membership?->family_category_id)->toBe($student->id);
});

it('creates managed members with a temporary-password change requirement and canonical membership', function () {
    $family = Family::factory()->create();
    $category = FamilyCategory::factory()->create([
        'family_id' => $family->id,
        'name' => 'Member',
        'slug' => 'member',
        'monthly_amount' => 2500,
    ]);
    $admin = User::factory()->admin()->create(['family_id' => $family->id]);

    $this->actingAs($admin)
        ->post('/members', [
            'name' => 'Managed Member',
            'email' => 'managed@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'family_category_id' => $category->id,
            'role' => Role::Member->value,
        ])
        ->assertRedirect();

    $member = User::query()->where('email', 'managed@example.com')->firstOrFail();
    $membership = $member->membershipForFamily($family);

    expect($member->must_change_password_at)->not->toBeNull()
        ->and($membership)->toBeInstanceOf(FamilyMembership::class)
        ->and($membership?->display_name)->toBe('Managed Member')
        ->and($membership?->family_category_id)->toBe($category->id)
        ->and($membership?->categoryAssignments()->count())->toBe(1);
});

it('guards category ownership and reconstructs missing category history', function () {
    Date::setTestNow('2026-07-13 10:00:00');

    $family = Family::factory()->create();
    $otherFamily = Family::factory()->create();
    $adult = FamilyCategory::factory()->create([
        'family_id' => $family->id,
        'name' => 'Adult',
        'slug' => 'adult',
        'monthly_amount' => 4000,
    ]);
    $student = FamilyCategory::factory()->create([
        'family_id' => $family->id,
        'name' => 'Student',
        'slug' => 'student',
        'monthly_amount' => 1000,
    ]);
    $foreignCategory = FamilyCategory::factory()->create(['family_id' => $otherFamily->id]);
    $actor = User::factory()->admin()->create(['family_id' => $family->id]);
    $member = User::factory()->member()->create([
        'family_id' => $family->id,
        'family_category_id' => $adult->id,
    ]);
    $membership = FamilyMembership::query()
        ->where('family_id', $family->id)
        ->where('user_id', $member->id)
        ->firstOrFail();

    expect(fn () => app(AssignFamilyCategory::class)->handle($membership, $foreignCategory, $actor))
        ->toThrow(InvalidArgumentException::class);

    $membership->forceFill(['created_at' => now()->subMonths(2)])->save();
    $membership->categoryAssignments()->delete();
    app(AssignFamilyCategory::class)->handle($membership, $student, $actor, effectiveImmediately: true);

    $assignments = $membership->categoryAssignments()->oldest('effective_from')->get();
    $currentAssignment = $membership->categoryAssignments()
        ->oldest('effective_from')
        ->skip(1)
        ->firstOrFail();

    expect($assignments)->toHaveCount(2)
        ->and($assignments->firstOrFail()->category_name)->toBe('Adult')
        ->and($currentAssignment->category_name)->toBe('Student')
        ->and($currentAssignment->assigner()->firstOrFail()->is($actor))->toBeTrue();

    $membership->categoryAssignments()->delete();
    $membership->forceFill(['family_category_id' => null, 'category' => null])->save();

    expect(app(AssignFamilyCategory::class)->handle($membership, null, $actor)->family_category_id)->toBeNull();
});

it('falls back to canonical and legacy category amounts when history is absent', function () {
    $family = Family::factory()->create();
    $category = FamilyCategory::factory()->create([
        'family_id' => $family->id,
        'name' => 'Adult',
        'slug' => 'adult',
        'monthly_amount' => 4500,
    ]);
    $member = User::factory()->member()->create([
        'family_id' => $family->id,
        'family_category_id' => $category->id,
    ]);
    $membership = FamilyMembership::query()
        ->where('family_id', $family->id)
        ->where('user_id', $member->id)
        ->firstOrFail();

    $membership->categoryAssignments()->delete();
    $membership->unsetRelation('familyCategory');

    expect($membership->monthlyAmountForPeriod(2026, 7))->toBe(4500);

    $membership->forceFill([
        'family_category_id' => null,
        'category' => MemberCategory::Employed,
    ])->save();
    $membership->unsetRelation('familyCategory');

    expect($membership->monthlyAmountForPeriod(2026, 7))->toBe(MemberCategory::Employed->monthlyAmount());
});
