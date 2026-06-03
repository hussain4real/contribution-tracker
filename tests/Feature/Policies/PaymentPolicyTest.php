<?php

declare(strict_types=1);

use App\Models\Contribution;
use App\Models\Family;
use App\Models\Payment;
use App\Models\User;
use App\Policies\PaymentPolicy;

beforeEach(function () {
    $this->family = Family::factory()->create();
    $this->otherFamily = Family::factory()->create();
    $this->admin = User::factory()->admin()->create(['family_id' => $this->family->id]);
    $this->financialSecretary = User::factory()->financialSecretary()->create(['family_id' => $this->family->id]);
    $this->member = User::factory()->member()->employed()->create(['family_id' => $this->family->id]);
    $this->otherMember = User::factory()->member()->employed()->create(['family_id' => $this->family->id]);
    $this->outsider = User::factory()->admin()->create(['family_id' => $this->otherFamily->id]);
    $this->policy = new PaymentPolicy;

    $this->contribution = Contribution::factory()->forUser($this->member)->create();
    $this->otherContribution = Contribution::factory()->forUser($this->otherMember)->create();
    $this->payment = Payment::factory()
        ->forContribution($this->contribution)
        ->recordedBy($this->admin)
        ->create();
    $this->otherPayment = Payment::factory()
        ->forContribution($this->otherContribution)
        ->recordedBy($this->admin)
        ->create();
});

it('allows any authenticated user to view the payments list', function () {
    expect($this->policy->viewAny($this->member))->toBeTrue();
});

it('allows family viewers and owners to view matching payments', function () {
    expect($this->policy->view($this->admin, $this->payment))->toBeTrue()
        ->and($this->policy->view($this->financialSecretary, $this->payment))->toBeTrue()
        ->and($this->policy->view($this->member, $this->payment))->toBeTrue();
});

it('denies payment viewing across families or for another member', function () {
    expect($this->policy->view($this->member, $this->otherPayment))->toBeFalse()
        ->and($this->policy->view($this->outsider, $this->payment))->toBeFalse();
});

it('allows admins and financial secretaries to create payments', function () {
    expect($this->policy->create($this->admin))->toBeTrue()
        ->and($this->policy->create($this->financialSecretary))->toBeTrue()
        ->and($this->policy->create($this->member))->toBeFalse();
});

it('allows only same-family admins to update payments', function () {
    expect($this->policy->update($this->admin, $this->payment))->toBeTrue()
        ->and($this->policy->update($this->financialSecretary, $this->payment))->toBeFalse()
        ->and($this->policy->update($this->outsider, $this->payment))->toBeFalse();
});

it('allows same-family admins to delete recent payments only', function () {
    $oldPayment = Payment::factory()
        ->forContribution($this->contribution)
        ->recordedBy($this->admin)
        ->create(['created_at' => now()->subHours(25)]);

    expect($this->policy->delete($this->admin, $this->payment))->toBeTrue()
        ->and($this->policy->delete($this->admin, $oldPayment))->toBeFalse()
        ->and($this->policy->delete($this->financialSecretary, $this->payment))->toBeFalse()
        ->and($this->policy->delete($this->outsider, $this->payment))->toBeFalse();
});

it('denies deleting payments without a creation timestamp', function () {
    expect($this->policy->delete($this->admin, new Payment))->toBeFalse();
});

it('allows only same-family admins to restore or force delete payments', function () {
    expect($this->policy->restore($this->admin, $this->payment))->toBeTrue()
        ->and($this->policy->forceDelete($this->admin, $this->payment))->toBeTrue()
        ->and($this->policy->restore($this->financialSecretary, $this->payment))->toBeFalse()
        ->and($this->policy->forceDelete($this->outsider, $this->payment))->toBeFalse();
});
