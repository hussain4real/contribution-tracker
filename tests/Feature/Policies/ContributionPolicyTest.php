<?php

use App\Models\Contribution;
use App\Models\Family;
use App\Models\User;
use App\Policies\ContributionPolicy;

beforeEach(function () {
    $this->family = Family::factory()->create();
    $this->otherFamily = Family::factory()->create();
    $this->admin = User::factory()->admin()->create(['family_id' => $this->family->id]);
    $this->financialSecretary = User::factory()->financialSecretary()->create(['family_id' => $this->family->id]);
    $this->member = User::factory()->member()->employed()->create(['family_id' => $this->family->id]);
    $this->otherMember = User::factory()->member()->employed()->create(['family_id' => $this->family->id]);
    $this->outsider = User::factory()->admin()->create(['family_id' => $this->otherFamily->id]);
    $this->policy = new ContributionPolicy;

    $this->contribution = Contribution::factory()->forUser($this->member)->create();
    $this->otherContribution = Contribution::factory()->forUser($this->otherMember)->create();
});

it('allows any authenticated user to view the contributions list and aggregate totals', function () {
    expect($this->policy->viewAny($this->member))->toBeTrue()
        ->and($this->policy->viewFamilyAggregate($this->member))->toBeTrue();
});

it('allows family viewers and owners to view matching contributions', function () {
    expect($this->policy->view($this->admin, $this->contribution))->toBeTrue()
        ->and($this->policy->view($this->financialSecretary, $this->contribution))->toBeTrue()
        ->and($this->policy->view($this->member, $this->contribution))->toBeTrue();
});

it('denies contribution viewing across families or for another member', function () {
    expect($this->policy->view($this->member, $this->otherContribution))->toBeFalse()
        ->and($this->policy->view($this->outsider, $this->contribution))->toBeFalse();
});

it('allows only admins to create contributions', function () {
    expect($this->policy->create($this->admin))->toBeTrue()
        ->and($this->policy->create($this->financialSecretary))->toBeFalse()
        ->and($this->policy->create($this->member))->toBeFalse();
});

it('allows only same-family admins to mutate contributions', function (string $ability) {
    expect($this->policy->{$ability}($this->admin, $this->contribution))->toBeTrue()
        ->and($this->policy->{$ability}($this->financialSecretary, $this->contribution))->toBeFalse()
        ->and($this->policy->{$ability}($this->outsider, $this->contribution))->toBeFalse();
})->with(['update', 'delete', 'restore', 'forceDelete']);

it('allows only elevated roles to view member contribution details', function () {
    expect($this->policy->viewMemberDetails($this->admin))->toBeTrue()
        ->and($this->policy->viewMemberDetails($this->financialSecretary))->toBeTrue()
        ->and($this->policy->viewMemberDetails($this->member))->toBeFalse();
});
