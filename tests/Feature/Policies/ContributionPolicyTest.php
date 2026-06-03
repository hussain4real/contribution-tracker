<?php

declare(strict_types=1);

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

    $this->contribution = Contribution::factory()->forUser($this->member)->create();
    $this->otherContribution = Contribution::factory()->forUser($this->otherMember)->create();
});

it('allows any authenticated user to view the contributions list and aggregate totals', function () {
    $policy = new ContributionPolicy;

    expect($policy->viewAny($this->member))->toBeTrue()
        ->and($policy->viewFamilyAggregate($this->member))->toBeTrue();
});

it('allows family viewers and owners to view matching contributions', function () {
    $policy = new ContributionPolicy;

    expect($policy->view($this->admin, $this->contribution))->toBeTrue()
        ->and($policy->view($this->financialSecretary, $this->contribution))->toBeTrue()
        ->and($policy->view($this->member, $this->contribution))->toBeTrue();
});

it('denies contribution viewing across families or for another member', function () {
    $policy = new ContributionPolicy;

    expect($policy->view($this->member, $this->otherContribution))->toBeFalse()
        ->and($policy->view($this->outsider, $this->contribution))->toBeFalse();
});

it('allows only admins to create contributions', function () {
    $policy = new ContributionPolicy;

    expect($policy->create($this->admin))->toBeTrue()
        ->and($policy->create($this->financialSecretary))->toBeFalse()
        ->and($policy->create($this->member))->toBeFalse();
});

it('allows only same-family admins to mutate contributions', function (string $ability) {
    $policy = new ContributionPolicy;

    expect($policy->{$ability}($this->admin, $this->contribution))->toBeTrue()
        ->and($policy->{$ability}($this->financialSecretary, $this->contribution))->toBeFalse()
        ->and($policy->{$ability}($this->outsider, $this->contribution))->toBeFalse();
})->with(['update', 'delete', 'restore', 'forceDelete']);

it('allows only elevated roles to view member contribution details', function () {
    $policy = new ContributionPolicy;

    expect($policy->viewMemberDetails($this->admin))->toBeTrue()
        ->and($policy->viewMemberDetails($this->financialSecretary))->toBeTrue()
        ->and($policy->viewMemberDetails($this->member))->toBeFalse();
});
