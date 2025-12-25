<?php

use App\Enums\PaymentStatus;
use App\Models\Contribution;
use App\Models\Payment;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

describe('Record Full Payment', function () {
    beforeEach(function () {
        $this->financialSecretary = User::factory()->financialSecretary()->create();
        $this->member = User::factory()->member()->employed()->create();
    });

    it('financial secretary can access payment creation form', function () {
        $this->actingAs($this->financialSecretary)
            ->get(route('payments.create', $this->member))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Payments/Create')
                ->has('member')
            );
    });

    it('financial secretary can record a full payment', function () {
        $contribution = Contribution::factory()
            ->forUser($this->member)
            ->currentMonth()
            ->employed()
            ->create();

        $this->actingAs($this->financialSecretary)
            ->post(route('payments.store'), [
                'member_id' => $this->member->id,
                'amount' => 400000, // ₦4,000 full payment
                'paid_at' => now()->toDateString(),
                'notes' => 'Full payment received',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('payments', [
            'contribution_id' => $contribution->id,
            'amount' => 400000,
            'recorded_by' => $this->financialSecretary->id,
        ]);

        expect($contribution->fresh()->status)->toBe(PaymentStatus::Paid);
    });

    it('super admin can record a full payment', function () {
        $superAdmin = User::factory()->superAdmin()->create();

        $contribution = Contribution::factory()
            ->forUser($this->member)
            ->currentMonth()
            ->employed()
            ->create();

        $this->actingAs($superAdmin)
            ->post(route('payments.store'), [
                'member_id' => $this->member->id,
                'amount' => 400000,
                'paid_at' => now()->toDateString(),
            ])
            ->assertRedirect();

        expect($contribution->fresh()->status)->toBe(PaymentStatus::Paid);
    });

    it('contribution status changes to Paid after full payment', function () {
        $contribution = Contribution::factory()
            ->forUser($this->member)
            ->currentMonth()
            ->employed()
            ->create();

        expect($contribution->status)->not->toBe(PaymentStatus::Paid);

        $this->actingAs($this->financialSecretary)
            ->post(route('payments.store'), [
                'member_id' => $this->member->id,
                'amount' => 400000,
                'paid_at' => now()->toDateString(),
            ]);

        expect($contribution->fresh()->status)->toBe(PaymentStatus::Paid);
        expect($contribution->fresh()->balance)->toBe(0);
    });

    it('validates required fields', function () {
        $this->actingAs($this->financialSecretary)
            ->post(route('payments.store'), [])
            ->assertSessionHasErrors(['member_id', 'amount', 'paid_at']);
    });

    it('validates amount is positive', function () {
        $this->actingAs($this->financialSecretary)
            ->post(route('payments.store'), [
                'member_id' => $this->member->id,
                'amount' => -100,
                'paid_at' => now()->toDateString(),
            ])
            ->assertSessionHasErrors('amount');
    });

    it('validates paid_at is a valid date', function () {
        $this->actingAs($this->financialSecretary)
            ->post(route('payments.store'), [
                'member_id' => $this->member->id,
                'amount' => 100000,
                'paid_at' => 'invalid-date',
            ])
            ->assertSessionHasErrors('paid_at');
    });
});
