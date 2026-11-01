<?php

declare(strict_types=1);

/**
 * Browser test for Payment Recording Flow
 *
 * Run with: ./vendor/bin/pest tests/Browser/ --headed
 */

use App\Jobs\SendPaymentReceipt;
use App\Models\Contribution;
use App\Models\Payment;
use App\Models\PaymentBatch;
use App\Models\PaymentReceiptDelivery;
use FamilyFunds\D1Staging\D1StagingSessionHandler;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;

describe('Payment Recording Flow (Browser)', function () {
    beforeEach(function () {
        $this->family = createBrowserFamily();
        $this->financialSecretary = createBrowserFinancialSecretary($this->family, [
            'email' => 'fs@test.com',
        ]);

        $this->member = createBrowserMember($this->family, [
            'name' => 'John Doe',
        ]);

        $nextMonth = now()->startOfMonth()->addMonth();
        $this->contribution = Contribution::factory()
            ->forUser($this->member)
            ->forMonth($nextMonth->year, $nextMonth->month)
            ->employed()
            ->create();
    });

    it('allows financial secretary to record a payment through the UI', function () {
        $page = loginBrowserAs($this->financialSecretary);

        $page->navigate(route('payments.create', [
            'current_family' => $this->family->slug,
            'member' => $this->member,
        ]))
            ->assertSee('Record Payment')
            ->assertSee($this->member->name)
            ->assertSee('1 Month');

        fillBrowserFieldWithoutChange($page, '[name="amount"]', '4000');
        fillBrowserFieldWithoutChange($page, '[name="paid_at"]', now()->format('Y-m-d'));

        expect($page->script('() => document.querySelector("form")?.getAttribute("action")'))
            ->toBe("/{$this->family->slug}/payments");
        expect($page->script('() => document.querySelector("form")?.getAttribute("method")'))
            ->toBe('post');

        $page->click('Review payment')
            ->assertSee('Record Payment')
            ->assertSee($this->contribution->period_label.':');
        expect($page->script('() => document.activeElement?.id'))->toBe('review-heading');
        $page->click('button[type="submit"]')
            ->assertSee('Receipt #1: ₦4,000.00 recorded for John Doe.');

        expect($this->contribution->refresh()->isPaid())->toBeTrue();

        $page
            ->assertSee('Dashboard')
            ->assertSee('Receipt #1: ₦4,000.00 recorded for John Doe.')
            ->assertNoJavaScriptErrors();

    });

    it('shows pending contributions on payment form', function () {
        // Create a partial payment
        $this->contribution->payments()->create([
            'amount' => 2000,
            'paid_at' => now(),
            'recorded_by' => $this->financialSecretary->id,
        ]);

        $page = loginBrowserAs($this->financialSecretary);

        $page->navigate(route('payments.create', [
            'current_family' => $this->family->slug,
            'member' => $this->member,
        ]))
            ->assertSee('Pending Contributions')
            ->assertSee('remaining');
    });

    it('requires a fresh review when the amount changes during a slow review', function (): void {
        $page = loginBrowserAs($this->financialSecretary);
        $page->resize(390, 844)->navigate(route('payments.create', [
            'current_family' => $this->family->slug,
            'member' => $this->member,
        ]));
        fillBrowserFieldWithoutChange($page, '[name="amount"]', '4000');
        fillBrowserFieldWithoutChange($page, '[name="paid_at"]', now()->format('Y-m-d'));

        $reviewPath = json_encode(route('payments.review', ['current_family' => $this->family->slug], false), JSON_THROW_ON_ERROR);
        $page->script(<<<JS
            () => {
                const originalOpen = XMLHttpRequest.prototype.open;
                const originalSend = XMLHttpRequest.prototype.send;
                const delayed = new WeakSet();
                let first = true;
                XMLHttpRequest.prototype.open = function (method, url, ...rest) {
                    if (first && method === 'POST' && new URL(url, window.location.origin).pathname === {$reviewPath}) {
                        first = false;
                        delayed.add(this);
                    }
                    return originalOpen.call(this, method, url, ...rest);
                };
                XMLHttpRequest.prototype.send = function (...args) {
                    if (delayed.has(this)) {
                        window.releasePaymentReview = () => originalSend.apply(this, args);
                        return;
                    }
                    return originalSend.apply(this, args);
                };
            }
        JS);

        $page->click('Review payment')
            ->assertButtonDisabled('Preparing review...');
        fillBrowserFieldWithoutChange($page, '[name="amount"]', '2000');
        $page->script('() => window.releasePaymentReview()');
        $page->assertButtonEnabled('Review payment')
            ->assertNotPresent('[name="review_token"]')
            ->click('Review payment')
            ->assertSee($this->contribution->period_label.': ₦2,000.00')
            ->assertNoJavaScriptErrors();

        expect(PaymentBatch::count())->toBe(0)->and(Payment::count())->toBe(0);
    });

    it('warns before retrying an unconfirmed receipt without changing the payment', function () {
        $batch = PaymentBatch::factory()->create([
            'family_id' => $this->family->id,
            'family_membership_id' => $this->member->membershipForFamily($this->family)?->id,
            'member_name' => $this->member->name,
            'recorded_by' => $this->financialSecretary->id,
            'total_amount' => 1000,
        ]);
        $payment = Payment::factory()->create([
            'contribution_id' => $this->contribution->id,
            'payment_batch_id' => $batch->id,
            'recorded_by' => $this->financialSecretary->id,
            'amount' => 1000,
        ]);
        $delivery = PaymentReceiptDelivery::factory()->create([
            'payment_batch_id' => $batch->id, 'channel' => 'mail', 'status' => 'failed', 'failed_at' => now(),
        ]);
        $jobsBefore = DB::table('jobs')->count();
        $page = loginBrowserAs($this->financialSecretary);
        $page->navigate(route('payments.index', ['current_family' => $this->family->slug]))
            ->assertSee('Delivery could not be confirmed')
            ->assertSee('The delivery attempt did not complete. Check the recipient details and provider before retrying.');
        $page->script('() => { window.confirm = message => { sessionStorage.setItem("retry-warning", message); return false; }; }');

        $page->click('Retry')->assertSee('Delivery could not be confirmed');
        expect($page->script('() => sessionStorage.getItem("retry-warning")'))
            ->toBe('The earlier delivery may have succeeded. Retry and possibly send another receipt?');
        expect($delivery->refresh()->status)->toBe('failed')
            ->and(DB::table('jobs')->count())->toBe($jobsBefore);
        $page->script('() => { window.confirm = () => true; }');

        $page->click('Retry')
            ->assertSee('Receipt delivery queued again. The payment was not changed.')
            ->assertSee('Email: Queued')
            ->assertNoJavaScriptErrors();

        expect($delivery->refresh()->status)->toBe('queued')
            ->and(DB::table('jobs')->count())->toBe($jobsBefore + 1)
            ->and(PaymentBatch::count())->toBe(1)
            ->and(Payment::count())->toBe(1)
            ->and($payment->refresh()->amount)->toBe(1000);
    });

    it('retrieves a recorded receipt when the payment form is retried after review expiry', function () {
        $page = loginBrowserAs($this->financialSecretary);
        $page->navigate(route('payments.create', [
            'current_family' => $this->family->slug,
            'member' => $this->member,
        ]));
        $paidAt = now()->format('Y-m-d');
        fillBrowserFieldWithoutChange($page, '[name="amount"]', '4000');
        fillBrowserFieldWithoutChange($page, '[name="paid_at"]', $paidAt);
        $page->click('Review payment')->assertSee($this->contribution->period_label.':');
        $token = $page->script('() => document.querySelector("[name=review_token]")?.value');
        $key = $page->script('() => document.querySelector("[name=idempotency_key]")?.value');
        expect($token)->toBeString()->and($key)->toBeString();

        $this->withoutMiddleware(ResetBrowserSessionAttributes::class);
        $this->actingAs($this->financialSecretary)->post(route('payments.store', ['current_family' => $this->family->slug]), [
            'member_id' => $this->member->id, 'amount' => 4000, 'paid_at' => $paidAt,
            'method' => 'cash', 'idempotency_key' => $key, 'review_token' => $token,
        ])->assertSessionHasNoErrors();
        $this->withMiddleware(ResetBrowserSessionAttributes::class);
        $receipt = PaymentBatch::sole();

        $this->travel(16)->minutes();
        $page->click('button[type="submit"]')
            ->assertSee('Receipt #'.$receipt->receipt_number.': ₦4,000.00 recorded for John Doe.')
            ->assertNoJavaScriptErrors();
        expect(PaymentBatch::count())->toBe(1)->and(Payment::count())->toBe(1);
    });

    it('keeps a recorded payment safe to retry after an interrupted response', function (string $failure): void {
        $queue = Queue::fake();
        Route::post('/synthetic-payment-session-error', fn () => response('Synthetic session error', 500));
        $page = loginBrowserAs($this->financialSecretary);
        $page->navigate(route('payments.create', [
            'current_family' => $this->family->slug,
            'member' => $this->member,
        ]));
        fillBrowserFieldWithoutChange($page, '[name="amount"]', '4000');
        fillBrowserFieldWithoutChange($page, '[name="paid_at"]', now()->format('Y-m-d'));
        $page->click('Review payment')->assertSee($this->contribution->period_label.':');
        $token = $page->script('() => document.querySelector("[name=review_token]")?.value');
        $key = $page->script('() => document.querySelector("[name=idempotency_key]")?.value');
        expect($token)->toBeString()->and($key)->toBeString();
        $this->withoutMiddleware(ResetBrowserSessionAttributes::class);
        $this->actingAs($this->financialSecretary)->post(route('payments.store', ['current_family' => $this->family->slug]), [
            'member_id' => $this->member->id, 'amount' => 4000, 'paid_at' => now()->format('Y-m-d'),
            'method' => 'cash', 'idempotency_key' => $key, 'review_token' => $token,
        ])->assertSessionHasNoErrors();
        $this->withMiddleware(ResetBrowserSessionAttributes::class);
        $receipt = PaymentBatch::sole();
        $queuedCount = $queue->pushed(SendPaymentReceipt::class)->count();
        expect($queuedCount)->toBeGreaterThan(0);
        $paymentPath = json_encode(route('payments.store', ['current_family' => $this->family->slug], false), JSON_THROW_ON_ERROR);
        $failureMode = json_encode($failure, JSON_THROW_ON_ERROR);
        $page->script(<<<JS
            () => {
                const originalOpen = XMLHttpRequest.prototype.open;
                const originalSend = XMLHttpRequest.prototype.send;
                const interrupted = new WeakSet();
                let first = true;
                XMLHttpRequest.prototype.open = function (method, url, ...rest) {
                    if (first && method === 'POST' && new URL(url, window.location.origin).pathname === {$paymentPath}) {
                        first = false;
                        if ({$failureMode} === 'server') {
                            url = '/synthetic-payment-session-error';
                        } else {
                            interrupted.add(this);
                        }
                    }
                    return originalOpen.call(this, method, url, ...rest);
                };
                XMLHttpRequest.prototype.send = function (...args) {
                    if (interrupted.has(this)) {
                        queueMicrotask(() => this.dispatchEvent(new ProgressEvent('error')));
                        return;
                    }
                    return originalSend.apply(this, args);
                };
            }
        JS);

        $page->click('button[type="submit"]')
            ->assertSee("We couldn't confirm whether this payment was recorded.");
        $page->click('button[type="submit"]')
            ->assertSee('Receipt #'.$receipt->receipt_number.': ₦4,000.00 recorded for John Doe.')
            ->assertNoJavaScriptErrors();
        expect(PaymentBatch::count())->toBe(1)->and(Payment::count())->toBe(1)
            ->and($queue->pushed(SendPaymentReceipt::class)->count())->toBe($queuedCount);
    })->with(['server error' => 'server', 'network interruption' => 'network']);

    it('returns the existing receipt after a session conflict following payment recording', function (): void {
        require_once __DIR__.'/../../deployment/d1-proof/autoload.php';
        $queue = Queue::fake();
        $remote = new PDO('sqlite::memory:');
        $remote->exec('create table sessions(id text primary key,payload text,last_activity integer)');
        $remote->exec('create table injected_conflicts(id integer primary key)');
        $conflict = new ArrayObject(['pending' => false]);
        Http::preventStrayRequests();
        Http::fake(function (ClientRequest $request) use ($remote, $conflict): PromiseInterface {
            if ($request->url() !== 'https://familyfunds-d1-staging-sql.familyfunds-lab.workers.dev/query') {
                throw new RuntimeException('Unexpected synthetic session endpoint.');
            }
            $sql = $request['sql'];
            $params = $request['params'];
            if (! is_string($sql) || ! is_array($params)) {
                throw new RuntimeException('Invalid synthetic session request.');
            }
            if ($conflict['pending'] && PaymentBatch::query()->exists() && str_starts_with($sql, 'update sessions set payload=')) {
                $session = $remote->prepare('select payload from sessions where id=?');
                $session->execute([$params[2]]);
                $encoded = $session->fetchColumn();
                $encrypted = is_string($encoded) ? base64_decode($encoded, true) : false;
                if (! is_string($encrypted)) {
                    throw new RuntimeException('Expected a synthetic encrypted session.');
                }
                $encrypter = app(Encrypter::class);
                $serialized = $encrypter->decrypt($encrypted);
                $data = is_string($serialized) ? unserialize($serialized, ['allowed_classes' => false]) : false;
                if (! is_array($data)) {
                    throw new RuntimeException('Expected synthetic session data.');
                }
                $data['synthetic_concurrent_change'] = true;
                $change = $remote->prepare('update sessions set payload=? where id=?');
                $change->execute([base64_encode($encrypter->encrypt(serialize($data))), $params[2]]);
                $remote->exec('insert into injected_conflicts default values');
                $conflict['pending'] = false;
            }
            $statement = $remote->prepare($sql);
            $statement->execute($params);

            return Http::response(['database' => 'familyfunds-d1-web-staging', 'result' => ['results' => $statement->fetchAll(PDO::FETCH_ASSOC)]]);
        });
        config(['d1-staging.enabled' => true, 'session.driver' => 'browser-conflict-session', 'session.encrypt' => true, 'session.serialization' => 'php', 'session.lottery' => [0, 100]]);
        app('session')->extend('browser-conflict-session', fn (): D1StagingSessionHandler => new D1StagingSessionHandler(
            'https://familyfunds-d1-staging-sql.familyfunds-lab.workers.dev', 'synthetic-token', 'familyfunds-d1-web-staging', 120,
        ));
        $this->app->forgetInstance('session.store');
        app('auth')->forgetGuards();
        $page = loginBrowserAs($this->financialSecretary);
        $page->navigate(route('payments.create', ['current_family' => $this->family->slug, 'member' => $this->member]));
        fillBrowserFieldWithoutChange($page, '[name="amount"]', '4000');
        fillBrowserFieldWithoutChange($page, '[name="paid_at"]', now()->format('Y-m-d'));
        $page->click('Review payment')->assertSee($this->contribution->period_label.':');
        $conflict['pending'] = true;

        $page->click('button[type="submit"]')->assertSee("We couldn't confirm whether this payment was recorded.");
        $conflicts = $remote->query('select count(*) from injected_conflicts');
        if ($conflicts === false) {
            throw new RuntimeException('Could not inspect synthetic conflicts.');
        }
        expect($conflicts->fetchColumn())->toBe(1)
            ->and(PaymentBatch::count())->toBe(1)->and(Payment::count())->toBe(1);
        $receipt = PaymentBatch::sole();
        $queuedCount = $queue->pushed(SendPaymentReceipt::class)->count();
        expect($queuedCount)->toBeGreaterThan(0);
        $page->click('button[type="submit"]')
            ->assertSee('Receipt #'.$receipt->receipt_number.': ₦4,000.00 recorded for John Doe.')
            ->assertNoJavaScriptErrors();
        expect(PaymentBatch::count())->toBe(1)->and(Payment::count())->toBe(1)
            ->and($queue->pushed(SendPaymentReceipt::class)->count())->toBe($queuedCount);
    });

    it('quick amount buttons work correctly', function () {
        $page = loginBrowserAs($this->financialSecretary);

        $page->navigate(route('payments.create', [
            'current_family' => $this->family->slug,
            'member' => $this->member,
        ]))
            ->assertSee('1 Month')
            ->assertSee('2 Months')
            ->assertSee('3 Months')
            ->assertSee('6 Months')
            ->assertNoJavaScriptErrors();
    });
});
