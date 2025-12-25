<?php

use App\Models\Contribution;
use App\Models\User;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

describe('Payment Authorization', function () {
    beforeEach(function () {
        $this->member = User::factory()->member()->employed()->create();
        $this->contribution = Contribution::factory()
            ->forUser($this->member)
            ->currentMonth()
            ->create();
    });

    it('forbids regular members from recording payments', function () {
        $member = User::factory()->member()->create();

        $this->actingAs($member)
            ->post(route('payments.store'), [
                'member_id' => $this->member->id,
                'amount' => 400000,
                'paid_at' => now()->toDateString(),
            ])
            ->assertForbidden();
    });

    it('forbids regular members from accessing payment creation form', function () {
        $member = User::factory()->member()->create();

        $this->actingAs($member)
            ->get(route('payments.create', $this->member))
            ->assertForbidden();
    });

    it('allows financial secretary to record payments', function () {
        $financialSecretary = User::factory()->financialSecretary()->create();

        $this->actingAs($financialSecretary)
            ->post(route('payments.store'), [
                'member_id' => $this->member->id,
                'amount' => 400000,
                'paid_at' => now()->toDateString(),
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('payments', [
            'amount' => 400000,
            'recorded_by' => $financialSecretary->id,
        ]);
    });

    it('allows super admin to record payments', function () {
        $superAdmin = User::factory()->superAdmin()->create();

        $this->actingAs($superAdmin)
            ->post(route('payments.store'), [
                'member_id' => $this->member->id,
                'amount' => 400000,
                'paid_at' => now()->toDateString(),
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('payments', [
            'amount' => 400000,
            'recorded_by' => $superAdmin->id,
        ]);
    });

    it('forbids unauthenticated users from recording payments', function () {
        $this->post(route('payments.store'), [
            'member_id' => $this->member->id,
            'amount' => 400000,
            'paid_at' => now()->toDateString(),
        ])->assertRedirect(route('login'));
    });

    it('forbids unauthenticated users from accessing payment form', function () {
        $this->get(route('payments.create', $this->member))
            ->assertRedirect(route('login'));
    });
});
