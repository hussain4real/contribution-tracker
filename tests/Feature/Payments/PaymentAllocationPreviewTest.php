<?php

declare(strict_types=1);

use App\Actions\CorrectPaymentBatch;
use App\Actions\ReversePaymentBatch;
use App\Enums\PaymentMethod;
use App\Jobs\SendPaymentReceipt;
use App\Models\Contribution;
use App\Models\Family;
use App\Models\FamilyMembership;
use App\Models\Payment;
use App\Models\PaymentBatch;
use App\Models\User;
use App\Services\PaymentAllocationPreview;
use App\Services\PaymentAllocationService;
use FamilyFunds\D1Staging\D1StagingSessionHandler;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
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

it('does not allocate to an existing contribution beyond the advance limit', function () {
    $this->travelTo(now()->setDate(2026, 10, 31)->startOfDay());
    Queue::fake();
    $family = Family::factory()->create();
    $member = User::factory()->member()->employed()->create(['family_id' => $family->id]);
    $officer = User::factory()->financialSecretary()->create(['family_id' => $family->id]);
    Contribution::factory()->forUser($member)->forMonth(2026, 10)->create(['expected_amount' => 4000]);
    $future = Contribution::factory()->forUser($member)->forMonth(2027, 5)->create(['expected_amount' => 4000]);
    $data = ['member_id' => $member->id, 'amount' => 8000, 'paid_at' => now()->toDateString(),
        'method' => 'cash', 'idempotency_key' => (string) Str::uuid()];

    $review = $this->actingAs($officer)->postJson(route('payments.review', ['current_family' => $family->slug]), $data)->assertOk();
    $review->assertJsonPath('allocations.0.month', 10)->assertJsonPath('allocations.1.month', 11);
    $data['review_token'] = $review->json('token');
    $this->actingAs($officer)->post(route('payments.store', ['current_family' => $family->slug]), $data)->assertSessionHasNoErrors();
    expect(Payment::query()->where('contribution_id', $future->id)->count())->toBe(0);
});

it('enforces the advance limit when allocating without a payment review', function () {
    $this->travelTo(now()->setDate(2026, 10, 31)->startOfDay());
    Queue::fake();
    $family = Family::factory()->create();
    $member = User::factory()->member()->employed()->create(['family_id' => $family->id]);
    $officer = User::factory()->financialSecretary()->create(['family_id' => $family->id]);
    Contribution::factory()->forUser($member)->forMonth(2026, 10)->create(['expected_amount' => 4000]);
    $future = Contribution::factory()->forUser($member)->forMonth(2027, 5)->create(['expected_amount' => 4000]);

    $batch = app(PaymentAllocationService::class)->createBatch(member: $member, amount: 8000, paidAt: now(), recordedBy: $officer, family: $family);
    $periods = $batch->allocations()->with('contribution')->get()->map(fn (Payment $payment): int => ($payment->contribution ?? throw new LogicException('Missing contribution'))->month)->all();
    expect($periods)->toBe([10, 11])->and(Payment::query()->where('contribution_id', $future->id)->count())->toBe(0);
});

it('starts at the current month when the only recorded contribution is beyond the advance limit', function () {
    $this->travelTo(now()->setDate(2026, 10, 31)->startOfDay());
    Queue::fake();
    $family = Family::factory()->create();
    $member = User::factory()->member()->employed()->create(['family_id' => $family->id]);
    $officer = User::factory()->financialSecretary()->create(['family_id' => $family->id]);
    $membership = $member->membershipForFamily($family);
    assert($membership instanceof FamilyMembership);
    $future = Contribution::factory()->forUser($member)->forMonth(2027, 5)->create(['expected_amount' => 4000]);

    $preview = app(PaymentAllocationPreview::class)->preview($member, $family, $membership, 4000);
    expect($preview['allocations'][0]['month'])->toBe(10);
    expect(fn () => app(PaymentAllocationPreview::class)->preview($member, $family, $membership, 4000, 2027, 5))
        ->toThrow(InvalidArgumentException::class, 'six-month advance-payment limit');
    $batch = app(PaymentAllocationService::class)->createBatch(member: $member, amount: 4000, paidAt: now(), recordedBy: $officer, family: $family, expectedAllocations: $preview['allocations']);
    $contribution = $batch->allocations->sole()->contribution ?? throw new LogicException('Missing contribution');
    expect($contribution->month)->toBe(10)
        ->and(Payment::query()->where('contribution_id', $future->id)->count())->toBe(0);
});

it('does not silently reallocate a correction of a receipt beyond the advance limit', function () {
    $this->travelTo(now()->setDate(2026, 11, 1)->startOfDay());
    Queue::fake();
    $family = Family::factory()->create();
    $member = User::factory()->member()->employed()->create(['family_id' => $family->id]);
    $officer = User::factory()->financialSecretary()->create(['family_id' => $family->id]);
    $original = app(PaymentAllocationService::class)->createBatch(
        member: $member, amount: 4000, paidAt: now(), recordedBy: $officer, family: $family, targetYear: 2027, targetMonth: 5,
    );
    $this->travelTo(now()->setDate(2026, 10, 31)->startOfDay());

    expect(fn () => app(CorrectPaymentBatch::class)->handle(
        original: $original, actor: $officer, reason: 'Correct receipt', amount: 4000, paidAt: '2026-11-01', method: PaymentMethod::Cash,
    ))->toThrow(InvalidArgumentException::class, 'original receipt includes a contribution beyond');
    $original = $original->fresh() ?? throw new LogicException('Missing original receipt');
    expect(PaymentBatch::count())->toBe(1)->and($original->isReversed())->toBeFalse();
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

it('replays the same reviewed payment when allocation rows are returned in a different order', function () {
    $this->travelTo(now()->setDate(2026, 10, 15)->startOfDay());
    Queue::fake();
    $family = Family::factory()->create();
    $member = User::factory()->member()->employed()->create(['family_id' => $family->id]);
    $officer = User::factory()->financialSecretary()->create(['family_id' => $family->id]);
    $first = Contribution::factory()->forUser($member)->forMonth(2026, 9)->create(['expected_amount' => 4000]);
    $second = Contribution::factory()->forUser($member)->forMonth(2026, 10)->create(['expected_amount' => 4000]);
    $data = ['member_id' => $member->id, 'amount' => 8000, 'paid_at' => now()->toDateString(),
        'method' => 'cash', 'idempotency_key' => (string) Str::uuid()];
    $data['review_token'] = $this->actingAs($officer)->postJson(route('payments.review', ['current_family' => $family->slug]), $data)->assertOk()->json('token');
    $url = route('payments.store', ['current_family' => $family->slug]);
    $this->actingAs($officer)->post($url, $data)->assertSessionHasNoErrors();
    $receipt = PaymentBatch::sole();
    $firstPayment = $receipt->allocations()->where('contribution_id', $first->id)->sole();
    DB::table('payments')->where('id', $firstPayment->id)->update(['id' => $firstPayment->id + 1000]);
    expect($receipt->allocations()->pluck('contribution_id')->all())->toBe([$second->id, $first->id]);

    $this->actingAs($officer)->post($url, $data)
        ->assertRedirect(route('payments.index', ['current_family' => $family->slug, 'receipt' => $receipt->id]))
        ->assertSessionHasNoErrors();
    expect(PaymentBatch::count())->toBe(1)->and(Payment::count())->toBe(2);
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

it('returns the original receipt when an unchanged recorded payment is retried after review expiry', function () {
    Queue::fake();
    $family = Family::factory()->create();
    $member = User::factory()->member()->employed()->create(['family_id' => $family->id]);
    $officer = User::factory()->financialSecretary()->create(['family_id' => $family->id]);
    $otherOfficer = User::factory()->admin()->create(['family_id' => $family->id]);
    Contribution::factory()->forUser($member)->currentMonth()->create(['expected_amount' => 4000]);
    $data = ['member_id' => $member->id, 'amount' => 1500, 'paid_at' => now()->toDateString(),
        'method' => 'cash', 'idempotency_key' => (string) Str::uuid()];
    $data['review_token'] = $this->actingAs($officer)->postJson(route('payments.review', ['current_family' => $family->slug]), $data)->assertOk()->json('token');
    $url = route('payments.store', ['current_family' => $family->slug]);
    $this->actingAs($officer)->post($url, $data)->assertSessionHasNoErrors();
    $receipt = PaymentBatch::sole();

    $this->travel(16)->minutes();
    $this->actingAs($officer)->post($url, $data)
        ->assertRedirect(route('payments.index', ['current_family' => $family->slug, 'receipt' => $receipt->id]))
        ->assertSessionHasNoErrors();
    $this->actingAs($officer)->post($url, [...$data, 'amount' => 1000])->assertSessionHasErrors('review');
    $this->actingAs($otherOfficer)->post($url, $data)->assertSessionHasErrors('review');
    app(ReversePaymentBatch::class)->handle($receipt, $officer, 'Duplicate payment');
    $this->actingAs($officer)->post($url, $data)
        ->assertRedirect(route('payments.index', ['current_family' => $family->slug, 'receipt' => $receipt->id]))
        ->assertSessionHas('success', 'Receipt #'.$receipt->receipt_number.' for ₦1,500.00 was recorded and later reversed.');
    expect(PaymentBatch::count())->toBe(1)->and(Payment::count())->toBe(1);
});

it('returns the original receipt when session saving fails after a payment is recorded', function () {
    require_once __DIR__.'/../../../deployment/d1-proof/autoload.php';
    $queue = Queue::fake();
    $family = Family::factory()->create();
    $member = User::factory()->member()->employed()->create(['family_id' => $family->id]);
    $officer = User::factory()->financialSecretary()->create(['family_id' => $family->id]);
    Contribution::factory()->forUser($member)->currentMonth()->create(['expected_amount' => 4000]);
    $data = ['member_id' => $member->id, 'amount' => 1500, 'paid_at' => now()->toDateString(),
        'method' => 'cash', 'idempotency_key' => (string) Str::uuid()];
    $data['review_token'] = $this->actingAs($officer)->postJson(route('payments.review', ['current_family' => $family->slug]), $data)->assertOk()->json('token');

    $remote = new PDO('sqlite::memory:');
    $remote->exec('create table sessions(id text primary key,payload text,last_activity integer)');
    $failNextSessionWrite = true;
    Http::preventStrayRequests();
    Http::fake(function (ClientRequest $request) use ($remote, &$failNextSessionWrite): PromiseInterface {
        if ($request->url() !== 'https://familyfunds-d1-staging-sql.familyfunds-lab.workers.dev/query') {
            throw new RuntimeException('Unexpected synthetic session endpoint.');
        }
        $sql = $request['sql'];
        $params = $request['params'];
        if (! is_string($sql) || ! is_array($params)) {
            throw new RuntimeException('Invalid synthetic session request.');
        }
        if ($failNextSessionWrite && PaymentBatch::query()->exists() && str_starts_with($sql, 'insert into sessions(')) {
            $failNextSessionWrite = false;

            return Http::response(['error' => 'Synthetic session write failure'], 503);
        }
        $statement = $remote->prepare($sql);
        $statement->execute($params);

        return Http::response(['database' => 'familyfunds-d1-web-staging', 'result' => ['results' => $statement->fetchAll(PDO::FETCH_ASSOC)]]);
    });
    config(['d1-staging.enabled' => true, 'session.driver' => 'payment-conflict-session', 'session.encrypt' => true, 'session.lottery' => [0, 100]]);
    app('session')->extend('payment-conflict-session', fn (): D1StagingSessionHandler => new D1StagingSessionHandler(
        'https://familyfunds-d1-staging-sql.familyfunds-lab.workers.dev', 'synthetic-token', 'familyfunds-d1-web-staging', 120,
    ));

    $url = route('payments.store', ['current_family' => $family->slug]);
    $failed = $this->actingAs($officer)->post($url, $data);
    $failed->assertInternalServerError();
    if (! $failed->baseResponse instanceof Response) {
        throw new RuntimeException('Expected an HTTP error response.');
    }
    expect($failed->baseResponse->exception)->toBeInstanceOf(RuntimeException::class)
        ->and($failed->baseResponse->exception?->getMessage())->toContain('D1 operation failed')
        ->and($failNextSessionWrite)->toBeFalse()
        ->and(PaymentBatch::count())->toBe(1)
        ->and(Payment::count())->toBe(1);
    $receipt = PaymentBatch::sole();
    $deliveryCount = $receipt->receiptDeliveries()->count();
    $queuedCount = $queue->pushed(SendPaymentReceipt::class)->count();
    expect($deliveryCount)->toBe(2)->and($queuedCount)->toBeGreaterThan(0);
    $this->actingAs($officer)->post($url, $data)
        ->assertRedirect(route('payments.index', ['current_family' => $family->slug, 'receipt' => $receipt->id]))
        ->assertSessionHasNoErrors();
    expect(PaymentBatch::count())->toBe(1)->and(Payment::count())->toBe(1)
        ->and($receipt->receiptDeliveries()->count())->toBe($deliveryCount)
        ->and($queue->pushed(SendPaymentReceipt::class)->count())->toBe($queuedCount);
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
