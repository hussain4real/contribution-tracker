<?php

declare(strict_types=1);

use App\Actions\ReversePaymentBatch;
use App\Models\Contribution;
use App\Models\Family;
use App\Models\FamilyCategory;
use App\Models\FamilyInvitation;
use App\Models\Payment;
use App\Models\PaymentBatch;
use App\Models\User;
use App\Services\FamilyOnboarding;
use App\Services\PaymentAllocationService;
use Inertia\Testing\AssertableInertia as Assert;

it('shows resumable family setup without inserting example members or money', function () {
    $family = Family::factory()->create(['bank_name' => null, 'account_name' => null, 'account_number' => null]);
    $admin = User::factory()->admin()->create(['family_id' => $family->id]);
    $before = [User::count(), Contribution::count(), Payment::count(), PaymentBatch::count()];
    $this->actingAs($admin)->get(route('family.onboarding'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Family/Onboarding')->where('guide.total', 4)->has('guide.steps', 6)
        ->where('guide.steps.0.completed', true)->where('guide.steps.2.completed', false)
        ->where('guide.steps.3.completed', false)->where('guide.steps.4.optional', true));
    expect([User::count(), Contribution::count(), Payment::count(), PaymentBatch::count()])->toBe($before);
    $this->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page->has('onboarding')->where('onboarding.total', 4));
});

it('derives current progress from this family including invitations and optional settings', function () {
    $family = Family::factory()->create(['bank_name' => 'Example Bank', 'account_name' => 'Family', 'account_number' => '1234567890', 'reminder_settings' => Family::DEFAULT_REMINDER_SETTINGS]);
    $admin = User::factory()->admin()->create(['family_id' => $family->id]);
    FamilyCategory::factory()->create(['family_id' => $family->id]);
    FamilyInvitation::factory()->create(['family_id' => $family->id, 'invited_by' => $admin->id, 'expires_at' => now()->addDay()]);
    $guide = app(FamilyOnboarding::class)->forFamily($family);
    expect($guide['completed'])->toBe(3)->and($guide['steps'][1]['completed'])->toBeTrue()
        ->and($guide['steps'][2]['completed'])->toBeTrue()->and($guide['steps'][4]['completed'])->toBeTrue()
        ->and($guide['steps'][5]['completed'])->toBeTrue();
    FamilyInvitation::query()->where('family_id', $family->id)->update(['expires_at' => now()->subDay()]);
    expect(app(FamilyOnboarding::class)->forFamily($family)['steps'][2]['completed'])->toBeFalse();
    User::factory()->member()->create(['family_id' => $family->id]);
    expect(app(FamilyOnboarding::class)->forFamily($family)['steps'][2]['completed'])->toBeTrue();
});

it('counts effective collections and ignores another family and reversed receipts', function () {
    $family = Family::factory()->create();
    $admin = User::factory()->admin()->employed()->create(['family_id' => $family->id]);
    Contribution::factory()->forUser($admin)->currentMonth()->create(['expected_amount' => 4000]);
    $batch = app(PaymentAllocationService::class)->createBatch(member: $admin, amount: 1000, paidAt: now(), recordedBy: $admin, family: $family);
    expect(app(FamilyOnboarding::class)->forFamily($family)['steps'][3]['completed'])->toBeTrue();
    app(ReversePaymentBatch::class)->handle($batch, $admin, 'Synthetic reversal');
    expect(app(FamilyOnboarding::class)->forFamily($family)['steps'][3]['completed'])->toBeFalse();
    $other = Family::factory()->create();
    FamilyInvitation::factory()->create(['family_id' => $other->id]);
    expect(app(FamilyOnboarding::class)->forFamily($family)['steps'][2]['completed'])->toBeFalse();
});

it('restricts setup to an active admin in the selected family', function () {
    $family = Family::factory()->create();
    $member = User::factory()->member()->create(['family_id' => $family->id]);
    $this->actingAs($member)->get(route('family.onboarding'))->assertForbidden();
    $this->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page->missing('onboarding'));
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin)->get(route('family.onboarding', ['current_family' => $family->slug]))->assertForbidden();
});
