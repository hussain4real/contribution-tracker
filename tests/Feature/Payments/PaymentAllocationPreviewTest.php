<?php

declare(strict_types=1);

use App\Models\Contribution;
use App\Models\Family;
use App\Models\FamilyMembership;
use App\Models\Payment;
use App\Models\PaymentBatch;
use App\Models\User;
use App\Services\PaymentAllocationPreview;
use App\Services\PaymentAllocationService;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;

it('applies the six-month advance limit in a month-end payment review', function () {
    $this->travelTo(now()->setDate(2026, 10, 31)->startOfDay());
    $family = Family::factory()->create();
    $member = User::factory()->member()->employed()->create(['family_id' => $family->id]);
    $membership = $member->membershipForFamily($family);
    assert($membership instanceof FamilyMembership);
    $preview = app(PaymentAllocationPreview::class);

    $valid = $preview->preview($member, $family, $membership, 28000);
    expect($valid['allocations'])->toHaveCount(7)
        ->and($valid['allocations'][6]['month'])->toBe(4)
        ->and($valid['allocations'][6]['year'])->toBe(2027);
    expect(fn () => $preview->preview($member, $family, $membership, 32000))
        ->toThrow(InvalidArgumentException::class, 'six-month advance-payment limit');
});

it('previews the actual allocation without writing financial records', function (string $scenario) {
    $this->travelTo(now()->setDate(2026, 10, 15)->startOfDay());
    Queue::fake();
    $family = Family::factory()->create();
    $member = User::factory()->member()->employed()->create(['family_id' => $family->id]);
    $officer = User::factory()->financialSecretary()->create(['family_id' => $family->id]);
    $membership = $member->membershipForFamily($family);
    assert($membership instanceof FamilyMembership);
    if ($scenario !== 'empty') {
        $first = Contribution::factory()->forUser($member)->forMonth(2026, 9)->create(['expected_amount' => 4000]);
        Contribution::factory()->forUser($member)->forMonth(2026, 10)->create(['expected_amount' => 4000]);
        Payment::factory()->forContribution($first)->create(['amount' => $scenario === 'paid' ? 4000 : 1500]);
    }
    $targetYear = $scenario === 'target' ? 2026 : null;
    $targetMonth = $scenario === 'target' ? 12 : null;
    $before = [Contribution::count(), Payment::count(), PaymentBatch::count()];

    $preview = app(PaymentAllocationPreview::class)->preview($member, $family, $membership, 5000, $targetYear, $targetMonth);

    expect([Contribution::count(), Payment::count(), PaymentBatch::count()])->toBe($before);
    $batch = app(PaymentAllocationService::class)->createBatch(
        member: $member, amount: 5000, paidAt: now(), recordedBy: $officer,
        targetYear: $targetYear, targetMonth: $targetMonth, family: $family,
        expectedAllocations: $preview['allocations'],
    );
    $actual = $batch->allocations()->with('contribution')->get()->map(function (Payment $payment): array {
        $contribution = $payment->contribution ?? throw new LogicException('Allocation has no contribution.');

        return ['year' => $contribution->year, 'month' => $contribution->month,
            'period' => $contribution->period_label, 'amount' => $payment->amount];
    })->all();

    expect($preview['allocations'])->toBe($actual);
})->with(['empty', 'partial', 'paid', 'target']);

it('rejects amounts beyond the advance limit without creating entries', function () {
    $this->travelTo(now()->setDate(2026, 10, 15)->startOfDay());
    $family = Family::factory()->create();
    $member = User::factory()->member()->employed()->create(['family_id' => $family->id]);
    $membership = $member->membershipForFamily($family);
    assert($membership instanceof FamilyMembership);

    expect(fn () => app(PaymentAllocationPreview::class)->preview($member, $family, $membership, 999999))
        ->toThrow(InvalidArgumentException::class, 'six-month advance-payment limit');
    expect(Contribution::count())->toBe(0)->and(PaymentBatch::count())->toBe(0);
});

it('rejects a non-positive preview and a future period without a contribution category', function () {
    $family = Family::factory()->create();
    $member = User::factory()->member()->create(['family_id' => $family->id, 'category' => null, 'family_category_id' => null]);
    $membership = $member->membershipForFamily($family);
    assert($membership instanceof FamilyMembership);
    $membership->update(['family_category_id' => null]);

    expect(fn () => app(PaymentAllocationPreview::class)->preview($member, $family, $membership, 0))
        ->toThrow(InvalidArgumentException::class, 'at least one whole currency unit');
    expect(fn () => app(PaymentAllocationPreview::class)->preview($member, $family, $membership, 1000))
        ->toThrow(InvalidArgumentException::class, 'no contribution category');
    expect(PaymentBatch::count())->toBe(0);
});

it('reviews and posts the same allocations and safely replays the reviewed request', function () {
    Queue::fake();
    $family = Family::factory()->create();
    $member = User::factory()->member()->employed()->create(['family_id' => $family->id]);
    $officer = User::factory()->financialSecretary()->create(['family_id' => $family->id]);
    Contribution::factory()->forUser($member)->currentMonth()->create(['expected_amount' => 4000]);
    $data = ['member_id' => $member->id, 'amount' => 1500, 'paid_at' => now()->toDateString(),
        'method' => 'cash', 'reference' => 'review-reference', 'idempotency_key' => (string) Str::uuid()];
    $review = $this->actingAs($officer)->postJson(route('payments.review', ['current_family' => $family->slug]), $data)->assertOk();
    $review->assertJsonPath('allocations.0.amount', 1500)->assertJsonPath('possible_duplicate', false);
    expect(PaymentBatch::count())->toBe(0);
    $data['review_token'] = $review->json('token');
    $response = $this->actingAs($officer)->post(route('payments.store', ['current_family' => $family->slug]), $data);
    $receipt = PaymentBatch::sole();
    $response->assertRedirect(route('payments.index', ['current_family' => $family->slug, 'receipt' => $receipt->id]))->assertSessionHasNoErrors();
    $this->actingAs($officer)->get(route('payments.index', ['current_family' => $family->slug, 'receipt' => $receipt->id]))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('receipts.0.id', $receipt->id)->has('receipts.0.deliveries'));
    $this->actingAs($officer)->post(route('payments.store', ['current_family' => $family->slug]), $data)->assertRedirect()->assertSessionHasNoErrors();
    expect(PaymentBatch::count())->toBe(1)->and(Payment::count())->toBe(1);
    $different = [...$data, 'amount' => 1000];
    unset($different['review_token']);
    $different['review_token'] = $this->actingAs($officer)->postJson(route('payments.review', ['current_family' => $family->slug]), $different)->assertOk()->json('token');
    $this->actingAs($officer)->post(route('payments.store', ['current_family' => $family->slug]), $different)->assertSessionHasErrors('review');
    expect(PaymentBatch::count())->toBe(1);
    unset($data['review_token']);
    $data['idempotency_key'] = (string) Str::uuid();
    $this->actingAs($officer)->postJson(route('payments.review', ['current_family' => $family->slug]), $data)
        ->assertOk()->assertJsonPath('possible_duplicate', true);
});

it('does not record a payment submitted without review', function () {
    $family = Family::factory()->create();
    $member = User::factory()->member()->employed()->create(['family_id' => $family->id]);
    $officer = User::factory()->financialSecretary()->create(['family_id' => $family->id]);
    $this->actingAs($officer)->post(route('payments.store', ['current_family' => $family->slug]), [
        'member_id' => $member->id, 'amount' => 1000, 'paid_at' => now()->toDateString(),
        'idempotency_key' => (string) Str::uuid(),
    ])->assertSessionHasErrors('review');
    expect(PaymentBatch::count())->toBe(0);
});

it('rejects a review without an idempotency key or beyond the allocation limit', function () {
    $family = Family::factory()->create();
    $member = User::factory()->member()->employed()->create(['family_id' => $family->id]);
    $officer = User::factory()->financialSecretary()->create(['family_id' => $family->id]);
    $url = route('payments.review', ['current_family' => $family->slug]);
    $data = ['member_id' => $member->id, 'amount' => 1000, 'paid_at' => now()->toDateString(), 'method' => 'cash'];

    $this->actingAs($officer)->postJson($url, $data)->assertUnprocessable()->assertJsonValidationErrors('idempotency_key');
    $this->actingAs($officer)->postJson($url, [...$data, 'amount' => 999999, 'idempotency_key' => (string) Str::uuid()])
        ->assertUnprocessable()->assertJsonValidationErrors('amount');
    expect(PaymentBatch::count())->toBe(0);
});

it('rejects a malformed allocation inside a valid review token', function () {
    $family = Family::factory()->create();
    $member = User::factory()->member()->employed()->create(['family_id' => $family->id]);
    $officer = User::factory()->financialSecretary()->create(['family_id' => $family->id]);
    Contribution::factory()->forUser($member)->currentMonth()->create(['expected_amount' => 4000]);
    $data = ['member_id' => $member->id, 'amount' => 1000, 'paid_at' => now()->toDateString(),
        'method' => 'cash', 'idempotency_key' => (string) Str::uuid()];
    $token = $this->actingAs($officer)->postJson(route('payments.review', ['current_family' => $family->slug]), $data)->assertOk()->json('token');
    if (! is_string($token)) {
        throw new RuntimeException('Payment review did not return a token.');
    }
    $payload = json_decode(Crypt::decryptString($token), true, flags: JSON_THROW_ON_ERROR);
    if (! is_array($payload)) {
        throw new RuntimeException('Payment review token did not contain an object.');
    }
    $payload['allocations'] = [['year' => 'invalid']];
    $data['review_token'] = Crypt::encryptString(json_encode($payload, JSON_THROW_ON_ERROR));

    $this->actingAs($officer)->post(route('payments.store', ['current_family' => $family->slug]), $data)->assertSessionHasErrors('review');
    expect(PaymentBatch::count())->toBe(0);
});

it('rejects expired or altered review tokens and tokens from another officer', function () {
    $family = Family::factory()->create();
    $member = User::factory()->member()->employed()->create(['family_id' => $family->id]);
    $officer = User::factory()->financialSecretary()->create(['family_id' => $family->id]);
    $otherOfficer = User::factory()->admin()->create(['family_id' => $family->id]);
    $data = ['member_id' => $member->id, 'amount' => 1000, 'paid_at' => now()->toDateString(),
        'idempotency_key' => (string) Str::uuid()];
    $token = $this->actingAs($officer)->postJson(route('payments.review', ['current_family' => $family->slug]), $data)
        ->assertOk()->json('token');
    $url = route('payments.store', ['current_family' => $family->slug]);
    $this->actingAs($officer)->post($url, [...$data, 'review_token' => 'invalid'])->assertSessionHasErrors('review');
    $this->actingAs($otherOfficer)->post($url, [...$data, 'review_token' => $token])->assertSessionHasErrors('review');
    $this->travel(16)->minutes();
    $this->actingAs($officer)->post($url, [...$data, 'review_token' => $token])->assertSessionHasErrors('review');
    expect(PaymentBatch::count())->toBe(0);
});

it('rejects changed payment details and stale allocations after review', function () {
    Queue::fake();
    $family = Family::factory()->create();
    $member = User::factory()->member()->employed()->create(['family_id' => $family->id]);
    $officer = User::factory()->financialSecretary()->create(['family_id' => $family->id]);
    $contribution = Contribution::factory()->forUser($member)->currentMonth()->create(['expected_amount' => 4000]);
    $data = ['member_id' => $member->id, 'amount' => 4000, 'paid_at' => now()->toDateString(),
        'method' => 'cash', 'idempotency_key' => (string) Str::uuid()];
    $review = $this->actingAs($officer)->postJson(route('payments.review', ['current_family' => $family->slug]), $data)->assertOk();
    $data['review_token'] = $review->json('token');
    $this->actingAs($officer)->post(route('payments.store', ['current_family' => $family->slug]), [...$data, 'amount' => 3000])
        ->assertSessionHasErrors('review');
    Payment::factory()->forContribution($contribution)->create(['amount' => 1000]);
    $this->actingAs($officer)->post(route('payments.store', ['current_family' => $family->slug]), $data)
        ->assertSessionHasErrors('review');
    expect(PaymentBatch::count())->toBe(0)->and(Payment::count())->toBe(1);
});

it('rolls back a payment when the reviewed allocation is stale', function () {
    $this->travelTo(now()->setDate(2026, 10, 15)->startOfDay());
    Queue::fake();
    $family = Family::factory()->create();
    $member = User::factory()->member()->employed()->create(['family_id' => $family->id]);
    $officer = User::factory()->financialSecretary()->create(['family_id' => $family->id]);
    $membership = $member->membershipForFamily($family);
    assert($membership instanceof FamilyMembership);
    $contribution = Contribution::factory()->forUser($member)->forMonth(2026, 10)->create(['expected_amount' => 4000]);
    $preview = app(PaymentAllocationPreview::class)->preview($member, $family, $membership, 4000);
    Payment::factory()->forContribution($contribution)->create(['amount' => 1000]);

    expect(fn () => app(PaymentAllocationService::class)->createBatch(
        member: $member, amount: 4000, paidAt: now(), recordedBy: $officer, family: $family,
        expectedAllocations: $preview['allocations'],
    ))->toThrow(ValidationException::class, 'Contribution balances changed');
    expect(PaymentBatch::count())->toBe(0)->and(Payment::count())->toBe(1)->and(Contribution::count())->toBe(1);
    Queue::assertNothingPushed();
});
