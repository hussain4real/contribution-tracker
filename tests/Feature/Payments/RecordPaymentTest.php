<?php

declare(strict_types=1);

use App\Enums\PaymentStatus;
use App\Models\Contribution;
use App\Models\Family;
use App\Models\FamilyCategory;
use App\Models\Payment;
use App\Models\User;
use App\Services\PaymentAllocationService;
use Inertia\Testing\AssertableInertia as Assert;

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

    it('financial secretary can view member selection for recording payments', function () {
        $family = Family::factory()->create();
        $financialSecretary = User::factory()->financialSecretary()->create([
            'family_id' => $family->id,
            'category' => null,
        ]);
        $activePayingMember = User::factory()->member()->employed()->create([
            'family_id' => $family->id,
            'name' => 'Active Paying',
        ]);
        User::factory()->member()->nonPaying()->create([
            'family_id' => $family->id,
            'name' => 'Non Paying',
        ]);
        User::factory()->member()->employed()->archived()->create([
            'family_id' => $family->id,
            'name' => 'Archived Paying',
        ]);

        $this->actingAs($financialSecretary)
            ->get(route('payments.index'))
            ->assertSuccessful()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Payments/Index')
                ->has('members', 1)
                ->where('members.0.id', $activePayingMember->id)
                ->where('members.0.name', 'Active Paying')
                ->where('members.0.monthly_amount', $activePayingMember->getMonthlyAmount())
            );
    });

    it('financial secretary can view custom category members for recording payments', function () {
        $family = Family::factory()->create(['currency' => 'QAR']);
        $monthlyDues = FamilyCategory::factory()->create([
            'family_id' => $family->id,
            'name' => 'Monthly Dues',
            'monthly_amount' => 100,
        ]);
        $financialSecretary = User::factory()->financialSecretary()->create([
            'family_id' => $family->id,
            'category' => null,
        ]);
        $member = User::factory()->member()->create([
            'family_id' => $family->id,
            'category' => null,
            'family_category_id' => $monthlyDues->id,
            'name' => 'Jamila Ladi Hussain',
        ]);

        $this->actingAs($financialSecretary)
            ->get(route('payments.index'))
            ->assertSuccessful()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Payments/Index')
                ->has('members', 1)
                ->where('members.0.id', $member->id)
                ->where('members.0.category_label', 'Monthly Dues')
                ->where('members.0.monthly_amount', 100)
            );
    });

    it('uses the family currency and actual monthly amount on payment forms', function () {
        $family = Family::factory()->create(['currency' => 'QAR']);
        $monthlyDues = FamilyCategory::factory()->create([
            'family_id' => $family->id,
            'name' => 'Monthly Dues',
            'monthly_amount' => 100,
        ]);
        $financialSecretary = User::factory()->financialSecretary()->create([
            'family_id' => $family->id,
            'category' => null,
        ]);
        $member = User::factory()->member()->create([
            'family_id' => $family->id,
            'category' => null,
            'family_category_id' => $monthlyDues->id,
        ]);

        $this->actingAs($financialSecretary)
            ->get(route('payments.create', $member))
            ->assertSuccessful()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Payments/Create')
                ->where('member.category_label', 'Monthly Dues')
                ->where('category_amount', 100)
                ->where('formatted_amount', 'QAR 100.00')
                ->where('categories.0.label', 'Employed (QAR 4,000/month)')
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
                'amount' => 4000, // ₦4,000 full payment
                'paid_at' => now()->toDateString(),
                'notes' => 'Full payment received',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('payments', [
            'contribution_id' => $contribution->id,
            'amount' => 4000,
            'recorded_by' => $this->financialSecretary->id,
        ]);

        expect($contribution->refresh()->status)->toBe(PaymentStatus::Paid);
    });

    it('uses the family currency in the payment recorded flash message', function () {
        $family = Family::factory()->create(['currency' => 'QAR']);
        $monthlyDues = FamilyCategory::factory()->create([
            'family_id' => $family->id,
            'name' => 'Monthly Dues',
            'monthly_amount' => 100,
        ]);
        $financialSecretary = User::factory()->financialSecretary()->create([
            'family_id' => $family->id,
        ]);
        $member = User::factory()->member()->create([
            'family_id' => $family->id,
            'category' => null,
            'family_category_id' => $monthlyDues->id,
        ]);

        Contribution::factory()
            ->forUser($member)
            ->currentMonth()
            ->create([
                'family_id' => $family->id,
                'expected_amount' => 100,
            ]);

        $this->actingAs($financialSecretary)
            ->post(route('payments.store'), [
                'member_id' => $member->id,
                'amount' => 100,
                'paid_at' => now()->toDateString(),
            ])
            ->assertRedirect(route('dashboard'))
            ->assertSessionHas('success', "Payment of QAR 100.00 recorded for {$member->name}.");
    });

    it('super admin can record a full payment', function () {
        $admin = User::factory()->admin()->create();

        $contribution = Contribution::factory()
            ->forUser($this->member)
            ->currentMonth()
            ->employed()
            ->create();

        $this->actingAs($admin)
            ->post(route('payments.store'), [
                'member_id' => $this->member->id,
                'amount' => 4000,
                'paid_at' => now()->toDateString(),
            ])
            ->assertRedirect();

        expect($contribution->refresh()->status)->toBe(PaymentStatus::Paid);
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
                'amount' => 4000,
                'paid_at' => now()->toDateString(),
            ]);

        $contribution->refresh();
        expect($contribution->status)->toBe(PaymentStatus::Paid);
        expect($contribution->balance)->toBe(0);
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

    it('validates the selected member has a contribution category', function () {
        $member = User::factory()->member()->nonPaying()->create([
            'family_id' => $this->financialSecretary->family_id,
        ]);

        $this->actingAs($this->financialSecretary)
            ->post(route('payments.store'), [
                'member_id' => $member->id,
                'amount' => 1000,
                'paid_at' => now()->toDateString(),
            ])
            ->assertSessionHasErrors('member_id');
    });

    it('validates paid_at is a valid date', function () {
        $this->actingAs($this->financialSecretary)
            ->post(route('payments.store'), [
                'member_id' => $this->member->id,
                'amount' => 1000,
                'paid_at' => 'invalid-date',
            ])
            ->assertSessionHasErrors('paid_at');
    });

    it('allows admins to delete recent payments', function () {
        $family = Family::factory()->create();
        $admin = User::factory()->admin()->create(['family_id' => $family->id]);
        $member = User::factory()->member()->employed()->create(['family_id' => $family->id]);
        $contribution = Contribution::factory()->forUser($member)->currentMonth()->create();
        $payment = Payment::factory()
            ->forContribution($contribution)
            ->recordedBy($admin)
            ->create();

        $this->actingAs($admin)
            ->delete(route('payments.destroy', $payment))
            ->assertRedirect()
            ->assertSessionHas('success', 'Payment has been deleted.');

        expect(Payment::whereKey($payment->id)->exists())->toBeFalse();
    });

    it('skips contributions that become paid after allocation candidates are loaded', function () {
        $family = Family::factory()->create();
        $admin = User::factory()->admin()->create(['family_id' => $family->id]);
        $member = User::factory()->member()->employed()->create(['family_id' => $family->id]);
        $contribution = Contribution::factory()->forUser($member)->currentMonth()->create([
            'expected_amount' => 4000,
        ]);

        $simulateRace = true;
        Contribution::retrieved(function (Contribution $retrieved) use (&$simulateRace, $contribution, $admin): void {
            if (! $simulateRace || $retrieved->id !== $contribution->id) {
                return;
            }

            $simulateRace = false;
            Payment::factory()
                ->forContribution($retrieved)
                ->recordedBy($admin)
                ->create(['amount' => 4000]);
        });

        $payments = app(PaymentAllocationService::class)->allocate(
            member: $member,
            amount: 4000,
            paidAt: now(),
            recordedBy: $admin,
        );

        expect($payments)->toHaveCount(1)
            ->and($payments->sole()->contribution_id)->not->toBe($contribution->id);
    });
});
