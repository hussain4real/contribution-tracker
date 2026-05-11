<?php

use App\Models\Family;
use App\Models\PlatformPlan;

it('formats free and paid plans and exposes families', function () {
    $free = PlatformPlan::create([
        'name' => 'Free',
        'slug' => 'free',
        'price' => 0,
        'max_members' => 5,
        'features' => [],
        'is_active' => true,
        'sort_order' => 0,
    ]);
    $paid = PlatformPlan::create([
        'name' => 'Starter',
        'slug' => 'starter',
        'price' => 2000,
        'max_members' => null,
        'features' => ['reports'],
        'is_active' => true,
        'sort_order' => 1,
    ]);
    $family = Family::factory()->create(['platform_plan_id' => $paid->id]);

    expect($free->formattedPrice())->toBe('Free')
        ->and($paid->formattedPrice())->toBe("\u{20A6}2,000")
        ->and($paid->isPaid())->toBeTrue()
        ->and($paid->hasUnlimitedMembers())->toBeTrue()
        ->and($paid->families->first()->is($family))->toBeTrue();
});
