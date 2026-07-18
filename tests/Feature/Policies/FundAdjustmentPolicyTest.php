<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Models\Family;
use App\Models\FundAdjustment;
use App\Models\User;
use App\Policies\FundAdjustmentPolicy;

beforeEach(function () {
    $this->family = Family::factory()->create();
    $this->otherFamily = Family::factory()->create();
    $this->admin = User::factory()->admin()->create(['family_id' => $this->family->id]);
    $this->financialSecretary = User::factory()->financialSecretary()->create(['family_id' => $this->family->id]);
    $this->member = User::factory()->member()->employed()->create(['family_id' => $this->family->id]);
    $this->outsider = User::factory()->admin()->create(['family_id' => $this->otherFamily->id]);
    $this->policy = new FundAdjustmentPolicy;
    $this->fundAdjustment = FundAdjustment::factory()->recordedBy($this->admin)->create();
});

it('allows any authenticated user to view the fund adjustments list', function () {
    expect($this->policy->viewAny($this->member))->toBeTrue();
});

it('allows only users in the same family to view a fund adjustment', function () {
    expect($this->policy->view($this->member, $this->fundAdjustment))->toBeTrue()
        ->and($this->policy->view($this->outsider, $this->fundAdjustment))->toBeFalse();
});

it('allows admins and financial secretaries to create fund adjustments', function () {
    expect($this->policy->create($this->admin))->toBeTrue()
        ->and($this->policy->create($this->financialSecretary))->toBeTrue()
        ->and($this->policy->create($this->member))->toBeFalse();
});

it('allows only same-family payment recorders to reverse fund adjustments', function () {
    expect($this->policy->delete($this->admin, $this->fundAdjustment))->toBeTrue()
        ->and($this->policy->delete($this->financialSecretary, $this->fundAdjustment))->toBeTrue()
        ->and($this->policy->delete($this->member, $this->fundAdjustment))->toBeFalse()
        ->and($this->policy->delete($this->outsider, $this->fundAdjustment))->toBeFalse();
});

it('uses the adjustment family role when a user belongs to multiple families', function () {
    $this->admin->ensureFamilyMembership($this->otherFamily, Role::Member);
    $otherAdjustment = FundAdjustment::factory()->recordedBy($this->outsider)->create([
        'family_id' => $this->otherFamily->id,
    ]);
    $otherFamilyOfficer = User::factory()->member()->create(['family_id' => $this->family->id]);
    $otherFamilyOfficer->ensureFamilyMembership($this->otherFamily, Role::FinancialSecretary);

    expect($this->policy->delete($this->admin, $otherAdjustment))->toBeFalse()
        ->and($this->policy->delete($otherFamilyOfficer, $otherAdjustment))->toBeTrue();

    $this->actingAs($this->admin)
        ->post(route('fund-adjustments.reverse', [
            'current_family' => $this->family->slug,
            'fund_adjustment' => $otherAdjustment,
        ]), ['reason' => 'Must not cross families'])
        ->assertForbidden();

    expect($otherAdjustment->reversal()->exists())->toBeFalse();
});

it('denies direct mutation of immutable fund adjustments', function (string $ability) {
    expect($this->policy->{$ability}($this->admin, $this->fundAdjustment))->toBeFalse()
        ->and($this->policy->{$ability}($this->financialSecretary, $this->fundAdjustment))->toBeFalse()
        ->and($this->policy->{$ability}($this->member, $this->fundAdjustment))->toBeFalse()
        ->and($this->policy->{$ability}($this->outsider, $this->fundAdjustment))->toBeFalse();
})->with(['update', 'restore', 'forceDelete']);
