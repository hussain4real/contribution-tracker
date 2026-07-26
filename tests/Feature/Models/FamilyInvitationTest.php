<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Models\Family;
use App\Models\FamilyInvitation;
use App\Models\User;
use Carbon\Carbon;

afterEach(function () {
    Carbon::setTestNow();
});

it('casts role and timestamp fields and exposes relationships', function () {
    $family = Family::factory()->create();
    $inviter = User::factory()->admin()->create(['family_id' => $family->id]);
    $invitation = FamilyInvitation::factory()->create([
        'family_id' => $family->id,
        'invited_by' => $inviter->id,
        'role' => Role::FinancialSecretary,
        'accepted_at' => '2026-05-10 08:00:00',
        'expires_at' => '2026-05-18 08:00:00',
    ]);

    expect($invitation->role)->toBe(Role::FinancialSecretary)
        ->and($invitation->accepted_at)->toBeInstanceOf(Carbon::class)
        ->and($invitation->expires_at)->toBeInstanceOf(Carbon::class)
        ->and($invitation->family()->firstOrFail()->is($family))->toBeTrue()
        ->and($invitation->inviter()->firstOrFail()->is($inviter))->toBeTrue();
});

it('identifies pending, accepted, and expired invitations', function () {
    Carbon::setTestNow('2026-05-11 12:00:00');

    $pending = FamilyInvitation::factory()->create(['expires_at' => now()->addDay()]);
    $accepted = FamilyInvitation::factory()->accepted()->create(['expires_at' => now()->subDay()]);
    $expired = FamilyInvitation::factory()->expired()->create();

    expect($pending->isPending())->toBeTrue()
        ->and($pending->isAccepted())->toBeFalse()
        ->and($pending->isExpired())->toBeFalse()
        ->and($accepted->isAccepted())->toBeTrue()
        ->and($accepted->isExpired())->toBeFalse()
        ->and($accepted->isPending())->toBeFalse()
        ->and($expired->isExpired())->toBeTrue()
        ->and($expired->isPending())->toBeFalse();
});

it('filters pending and expired invitations with local scopes', function () {
    Carbon::setTestNow('2026-05-11 12:00:00');

    $pending = FamilyInvitation::factory()->create(['expires_at' => now()->addDay()]);
    $expired = FamilyInvitation::factory()->expired()->create();
    FamilyInvitation::factory()->accepted()->create(['expires_at' => now()->addDay()]);

    expect(FamilyInvitation::pending()->pluck('id')->all())->toBe([$pending->id])
        ->and(FamilyInvitation::expired()->pluck('id')->all())->toBe([$expired->id]);
});
