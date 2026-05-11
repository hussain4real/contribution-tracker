<?php

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

it('allows only same-family payment recorders to mutate fund adjustments', function (string $ability) {
    expect($this->policy->{$ability}($this->admin, $this->fundAdjustment))->toBeTrue()
        ->and($this->policy->{$ability}($this->financialSecretary, $this->fundAdjustment))->toBeTrue()
        ->and($this->policy->{$ability}($this->member, $this->fundAdjustment))->toBeFalse()
        ->and($this->policy->{$ability}($this->outsider, $this->fundAdjustment))->toBeFalse();
})->with(['update', 'delete', 'restore', 'forceDelete']);
