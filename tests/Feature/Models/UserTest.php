<?php

declare(strict_types=1);

use App\Enums\MemberCategory;
use App\Enums\Role;
use App\Models\Contribution;
use App\Models\FamilyCategory;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

it('exposes payment relationships and query scopes', function () {
    $member = User::factory()->member()->employed()->create();
    $recorder = User::factory()->financialSecretary()->create();
    $contribution = Contribution::factory()->forUser($member)->create();
    $payment = Payment::factory()->forContribution($contribution)->recordedBy($recorder)->create();

    expect($recorder->recordedPayments()->firstOrFail()->is($payment))->toBeTrue()
        ->and($member->payments()->firstOrFail()->is($payment))->toBeTrue()
        ->and(User::members()->pluck('id')->all())->toContain($member->id)
        ->and(User::payingMembers()->pluck('id')->all())->toContain($member->id)
        ->and(User::withCategory(MemberCategory::Employed)->pluck('id')->all())->toContain($member->id)
        ->and(User::financialSecretaries()->pluck('id')->all())->toContain($recorder->id);
});

it('caches and forgets web push subscription status', function () {
    $user = User::factory()->create();

    expect($user->hasWebPushSubscription())->toBeFalse();

    $user->updatePushSubscription(
        'https://updates.push.services.mozilla.com/wpush/v2/test-endpoint',
        'test-public-key',
        'test-auth-token',
        'aes128gcm',
    );

    expect($user->hasWebPushSubscription())->toBeFalse();

    $user->forgetWebPushSubscriptionCache();

    expect($user->hasWebPushSubscription())->toBeTrue();

    Cache::forget("users.{$user->id}.web_push_subscribed");
});

it('reports role helpers and monthly amounts', function () {
    $category = FamilyCategory::factory()->create(['monthly_amount' => 7500]);
    $admin = User::factory()->admin()->create(['is_super_admin' => true]);
    $financialSecretary = User::factory()->financialSecretary()->create();
    $member = User::factory()->member()->create([
        'category' => MemberCategory::Student,
        'family_category_id' => $category->id,
    ]);

    expect($admin->isSuperAdmin())->toBeTrue()
        ->and($admin->isAdmin())->toBeTrue()
        ->and($admin->canManageMembers())->toBeTrue()
        ->and($financialSecretary->isFinancialSecretary())->toBeTrue()
        ->and($financialSecretary->canViewAllMembers())->toBeTrue()
        ->and($member->isMember())->toBeTrue()
        ->and($member->getMonthlyAmount())->toBe(7500)
        ->and(User::factory()->make(['role' => Role::Member, 'category' => null])->getMonthlyAmount())->toBeNull();
});

it('routes whatsapp notifications only for verified whatsapp numbers', function () {
    $verified = User::factory()->withVerifiedWhatsApp('+2348012345678')->create();
    $unverified = User::factory()->withUnverifiedWhatsApp('+2348012345678')->create();

    expect($verified->hasVerifiedWhatsApp())->toBeTrue()
        ->and($verified->routeNotificationForWhatsApp())->toBe('+2348012345678')
        ->and($unverified->hasVerifiedWhatsApp())->toBeFalse()
        ->and($unverified->routeNotificationForWhatsApp())->toBeNull();
});
