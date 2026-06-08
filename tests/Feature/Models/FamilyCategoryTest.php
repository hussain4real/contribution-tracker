<?php

declare(strict_types=1);

use App\Models\Family;
use App\Models\FamilyCategory;
use App\Models\User;

it('casts and formats family category amounts', function () {
    $category = FamilyCategory::factory()->create([
        'name' => 'Adults',
        'monthly_amount' => '4500',
        'sort_order' => '3',
    ]);

    expect($category->monthly_amount)->toBe(4500)
        ->and($category->sort_order)->toBe(3)
        ->and($category->formatted_amount)->toBe("\u{20A6}4,500")
        ->and($category->label_with_amount)->toBe("Adults (\u{20A6}4,500/month)");
});

it('formats family category amounts with the family currency', function () {
    $family = Family::factory()->create(['currency' => 'QAR']);
    $category = FamilyCategory::factory()->create([
        'family_id' => $family->id,
        'name' => 'Monthly Dues',
        'monthly_amount' => 100,
    ]);

    expect($category->formatted_amount)->toBe('QAR 100')
        ->and($category->label_with_amount)->toBe('Monthly Dues (QAR 100/month)');
});

it('exposes family and assigned user relationships', function () {
    $family = Family::factory()->create();
    $category = FamilyCategory::factory()->create(['family_id' => $family->id]);
    $user = User::factory()->member()->create([
        'family_id' => $family->id,
        'family_category_id' => $category->id,
    ]);

    expect($category->family()->firstOrFail()->is($family))->toBeTrue()
        ->and($category->users)->toHaveCount(1)
        ->and($category->users()->firstOrFail()->is($user))->toBeTrue();
});
