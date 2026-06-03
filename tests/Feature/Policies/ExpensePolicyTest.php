<?php

declare(strict_types=1);

use App\Models\Expense;
use App\Models\Family;
use App\Models\User;
use App\Policies\ExpensePolicy;

beforeEach(function () {
    $this->family = Family::factory()->create();
    $this->otherFamily = Family::factory()->create();
    $this->admin = User::factory()->admin()->create(['family_id' => $this->family->id]);
    $this->financialSecretary = User::factory()->financialSecretary()->create(['family_id' => $this->family->id]);
    $this->member = User::factory()->member()->employed()->create(['family_id' => $this->family->id]);
    $this->outsider = User::factory()->admin()->create(['family_id' => $this->otherFamily->id]);
    $this->policy = new ExpensePolicy;
    $this->expense = Expense::factory()->recordedBy($this->admin)->create();
});

it('allows any authenticated user to view the expenses list', function () {
    expect($this->policy->viewAny($this->member))->toBeTrue();
});

it('allows only users in the same family to view an expense', function () {
    expect($this->policy->view($this->member, $this->expense))->toBeTrue()
        ->and($this->policy->view($this->outsider, $this->expense))->toBeFalse();
});

it('allows admins and financial secretaries to create expenses', function () {
    expect($this->policy->create($this->admin))->toBeTrue()
        ->and($this->policy->create($this->financialSecretary))->toBeTrue()
        ->and($this->policy->create($this->member))->toBeFalse();
});

it('allows only same-family admins to mutate expenses', function (string $ability) {
    expect($this->policy->{$ability}($this->admin, $this->expense))->toBeTrue()
        ->and($this->policy->{$ability}($this->financialSecretary, $this->expense))->toBeFalse()
        ->and($this->policy->{$ability}($this->member, $this->expense))->toBeFalse()
        ->and($this->policy->{$ability}($this->outsider, $this->expense))->toBeFalse();
})->with(['update', 'delete', 'restore', 'forceDelete']);
