<?php

declare(strict_types=1);

use App\Models\Contribution;
use App\Models\Expense;
use App\Models\FamilyCategory;
use App\Models\FamilyInvitation;
use App\Models\FundAdjustment;
use App\Models\PlatformPlan;
use App\Models\ReportSchedule;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Pest\Browser\Api\PendingAwaitablePage;

function simulateFinancialContributionCheckout(PendingAwaitablePage $page, string $checkoutEvent, bool $dropVerification = false): void
{
    $manifestJson = file_get_contents(public_path('build/manifest.json'));
    if ($manifestJson === false) {
        throw new RuntimeException('The frontend build manifest is unavailable.');
    }
    $manifest = json_decode($manifestJson, true, flags: JSON_THROW_ON_ERROR);
    $entry = is_array($manifest) ? ($manifest['node_modules/@paystack/inline-js/es/inline.js'] ?? null) : null;
    $moduleFile = is_array($entry) ? ($entry['file'] ?? null) : null;
    if (! is_string($moduleFile)) {
        throw new RuntimeException('The Paystack module is missing from the frontend build.');
    }
    $moduleUrl = json_encode('/build/'.$moduleFile, JSON_THROW_ON_ERROR);
    $event = json_encode($checkoutEvent, JSON_THROW_ON_ERROR);
    $drop = json_encode($dropVerification, JSON_THROW_ON_ERROR);
    $page->script(<<<JS
        async () => {
            sessionStorage.setItem('checkout-verifications', '0');
            const blocked = new WeakSet();
            const originalOpen = XMLHttpRequest.prototype.open;
            XMLHttpRequest.prototype.open = function (...args) {
                if (String(args[1]).includes('/pay/callback')) {
                    sessionStorage.setItem('checkout-verifications', String(Number(sessionStorage.getItem('checkout-verifications')) + 1));
                    if ({$drop}) blocked.add(this);
                }
                return originalOpen.apply(this, args);
            };
            const originalSend = XMLHttpRequest.prototype.send;
            XMLHttpRequest.prototype.send = function (...args) {
                if (blocked.has(this)) {
                    queueMicrotask(() => this.dispatchEvent(new ProgressEvent('error')));
                    return;
                }
                return originalSend.apply(this, args);
            };
            const originalFetch = window.fetch.bind(window);
            window.fetch = (input, options) => {
                if (!String(input).includes('/pay/initiate')) return originalFetch(input, options);
                const cookie = document.cookie.split('; ').find(row => row.startsWith('XSRF-TOKEN='))?.slice('XSRF-TOKEN='.length);
                const header = new Headers(options.headers).get('X-XSRF-TOKEN');
                sessionStorage.setItem('checkout-xsrf-matches', String(Boolean(cookie) && header === decodeURIComponent(cookie)));
                return Promise.resolve(new Response(JSON.stringify({ access_code: 'synthetic', reference: 'TXN_MISSING' }), { status: 200, headers: { 'Content-Type': 'application/json' } }));
            };
            const { default: PaystackPop } = await import({$moduleUrl});
            PaystackPop.prototype.resumeTransaction = function (accessCode, callbacks) {
                callbacks[{$event}]({ message: 'Synthetic checkout event' });
                callbacks.onSuccess({});
            };
        }
        JS);
}

describe('Financial and family administration flows (Browser)', function () {
    beforeEach(function () {
        $this->family = createBrowserFamily([
            'name' => 'Browser Operations Family',
            'account_name' => 'Browser Operations Family',
            'account_number' => '0123456789',
        ]);
        $this->admin = createBrowserAdmin($this->family, [
            'email' => 'operations-admin@example.com',
        ]);
        $this->financialSecretary = createBrowserFinancialSecretary($this->family, [
            'email' => 'operations-secretary@example.com',
        ]);

        Cache::put('paystack_banks', [], now()->addDay());
    });

    it('records an expense through the UI', function () {
        $page = loginBrowserAs($this->financialSecretary);

        $page->navigate(route('expenses.create'))
            ->assertSee('Record Expense');

        $key = $page->script('() => document.querySelector("[name=idempotency_key]")?.value');
        expect($key)->toBeString()->not->toBeEmpty();
        fillBrowserFieldWithoutChange($page, '[name="amount"]', '1250');
        fillBrowserFieldWithoutChange($page, 'input[name="description"]', 'Browser workflow transport');
        fillBrowserFieldWithoutChange($page, '[name="spent_at"]', now()->format('Y-m-d'));

        $page->click('button[type="submit"]')
            ->assertSee('Expenses')
            ->assertSee('Browser workflow transport')
            ->assertNoJavaScriptErrors();

        expect(Expense::where('family_id', $this->family->id)
            ->where('recorded_by', $this->financialSecretary->id)
            ->where('description', 'Browser workflow transport')
            ->exists())->toBeTrue();
        $page->back()->assertSee('Record Expense');
        expect($page->script('() => document.querySelector("[name=idempotency_key]")?.value'))
            ->toBeString()->not->toBeEmpty()->not->toBe($key);

        fillBrowserFieldWithoutChange($page, '[name="amount"]', '1250');
        fillBrowserFieldWithoutChange($page, 'input[name="description"]', 'Browser workflow transport');
        fillBrowserFieldWithoutChange($page, '[name="spent_at"]', now()->format('Y-m-d'));
        $page->click('button[type="submit"]')
            ->assertSee('Browser workflow transport')
            ->assertNoJavaScriptErrors();

        expect(Expense::query()->where('family_id', $this->family->id)
            ->where('description', 'Browser workflow transport')->count())->toBe(2);
    });

    it('keeps an uncertain financial request key and warning after reload', function (string $kind, bool $committed): void {
        $isExpense = $kind === 'expense';
        $page = loginBrowserAs($isExpense ? $this->financialSecretary : $this->admin);
        $url = $isExpense ? route('expenses.create') : route('fund-adjustments.index');
        $postPath = json_encode($isExpense ? route('expenses.store', [], false) : route('fund-adjustments.store', [], false), JSON_THROW_ON_ERROR);
        $page->navigate($url);
        if (! $isExpense) {
            $page->click('Record Adjustment');
        }
        $key = $page->script('() => document.querySelector("[name=idempotency_key]")?.value');
        expect($key)->toBeString()->not->toBeEmpty();
        fillBrowserFieldWithoutChange($page, '[name="amount"]', '1250');
        fillBrowserFieldWithoutChange($page, 'input[name="description"]', 'Synthetic retry check');
        fillBrowserFieldWithoutChange($page, $isExpense ? '[name="spent_at"]' : '[name="recorded_at"]', now()->format('Y-m-d'));
        if ($committed) {
            $this->withoutMiddleware(ResetBrowserSessionAttributes::class);
            $data = ['idempotency_key' => $key, 'amount' => 1250, 'description' => 'Synthetic retry check',
                $isExpense ? 'spent_at' : 'recorded_at' => now()->format('Y-m-d')];
            $this->actingAs($isExpense ? $this->financialSecretary : $this->admin)
                ->post(route($isExpense ? 'expenses.store' : 'fund-adjustments.store'), $data)->assertSessionHasNoErrors();
            $this->withMiddleware(ResetBrowserSessionAttributes::class);
        }
        $page->script(<<<JS
            () => {
                const originalOpen = XMLHttpRequest.prototype.open;
                const originalSend = XMLHttpRequest.prototype.send;
                const interrupted = new WeakSet();
                XMLHttpRequest.prototype.open = function (method, url, ...rest) {
                    if (method === 'POST' && new URL(url, window.location.origin).pathname === {$postPath}) interrupted.add(this);
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
        $page->click('form button[type="submit"]')->assertSee('We could not confirm whether');
        $page->navigate($url);
        if (! $isExpense) {
            $page->click('Record Adjustment');
        }
        $page->assertSee('We could not confirm whether');
        expect($page->script('() => document.querySelector("[name=idempotency_key]")?.value'))->toBe($key);
        $page->script('() => { window.confirm = () => false; }');
        $page->click($isExpense ? 'Start a new expense' : 'Start a new adjustment');
        expect($page->script('() => document.querySelector("[name=idempotency_key]")?.value'))->toBe($key);

        fillBrowserFieldWithoutChange($page, '[name="amount"]', '1250');
        fillBrowserFieldWithoutChange($page, 'input[name="description"]', 'Synthetic retry check');
        fillBrowserFieldWithoutChange($page, $isExpense ? '[name="spent_at"]' : '[name="recorded_at"]', now()->format('Y-m-d'));
        $page->click('form button[type="submit"]')->assertSee('Synthetic retry check')->assertNoJavaScriptErrors();
        $count = $isExpense ? Expense::query()->where('description', 'Synthetic retry check')->count() : FundAdjustment::query()->where('description', 'Synthetic retry check')->count();
        expect($count)->toBe(1);
    })->with([['expense', false], ['expense', true], ['adjustment', false], ['adjustment', true]]);

    it('does not record a financial request when its retry key cannot be saved', function (string $kind): void {
        $isExpense = $kind === 'expense';
        $page = loginBrowserAs($isExpense ? $this->financialSecretary : $this->admin);
        $page->navigate($isExpense ? route('expenses.create') : route('fund-adjustments.index'));
        if (! $isExpense) {
            $page->click('Record Adjustment');
        }
        fillBrowserFieldWithoutChange($page, '[name="amount"]', '1250');
        fillBrowserFieldWithoutChange($page, 'input[name="description"]', 'Synthetic blocked request');
        fillBrowserFieldWithoutChange($page, $isExpense ? '[name="spent_at"]' : '[name="recorded_at"]', now()->format('Y-m-d'));
        $page->script('() => { Storage.prototype.setItem = () => { throw new Error("Synthetic storage failure"); }; }');
        $page->click('form button[type="submit"]')->assertSee('requires session storage to protect against duplicate entries');
        expect(Expense::query()->where('description', 'Synthetic blocked request')->count())->toBe(0)
            ->and(FundAdjustment::query()->where('description', 'Synthetic blocked request')->count())->toBe(0);
    })->with(['expense', 'adjustment']);

    it('shows members when online payments need Paystack setup', function () {
        config(['services.paystack.public_key' => 'pk_test_browser']);

        $this->family->update([
            'bank_code' => null,
            'account_number' => '0123456789',
        ]);

        $member = createBrowserMember($this->family, [
            'email' => 'paying-member@example.com',
        ]);
        Contribution::factory()
            ->forUser($member)
            ->currentMonth()
            ->create(['expected_amount' => 4000]);

        loginBrowserAs($member)->navigate("/{$this->family->slug}/pay")
            ->assertSee('Pay Contributions')
            ->assertSee('Bank details are saved, but the Paystack bank code is missing')
            ->assertNoJavaScriptErrors();
    });

    it('sends the current XSRF cookie when starting contribution checkout', function () {
        config(['services.paystack.public_key' => 'pk_test_synthetic', 'services.paystack.secret_key' => 'sk_test_synthetic']);
        $plan = PlatformPlan::create([
            'name' => 'Browser payments', 'slug' => 'browser-payments', 'price' => 0,
            'max_members' => null, 'features' => ['online_payments'], 'is_active' => true, 'sort_order' => 0,
        ]);
        $this->family->update(['platform_plan_id' => $plan->id, 'bank_code' => '058', 'account_number' => '1234567890']);
        $member = createBrowserMember($this->family);
        Contribution::factory()->forUser($member)->currentMonth()->create(['expected_amount' => 4000]);
        $page = loginBrowserAs($member);
        $page->navigate(route('pay.index', ['current_family' => $this->family->slug]))->assertSee('Pay Contributions');
        $page->script(<<<'JS'
            () => {
                const originalFetch = window.fetch.bind(window);
                window.fetch = (input, options) => {
                    if (!String(input).includes('/pay/initiate')) return originalFetch(input, options);
                    const cookie = document.cookie.split('; ').find(row => row.startsWith('XSRF-TOKEN='))?.slice('XSRF-TOKEN='.length);
                    const header = new Headers(options.headers).get('X-XSRF-TOKEN');
                    sessionStorage.setItem('checkout-xsrf-matches', String(Boolean(cookie) && header === decodeURIComponent(cookie)));
                    return Promise.resolve(new Response(JSON.stringify({ message: 'Synthetic checkout stopped' }), { status: 422, headers: { 'Content-Type': 'application/json' } }));
                };
            }
            JS);

        $page->click('Select All')
            ->click('button:has-text("with Paystack")')
            ->assertSee('Synthetic checkout stopped')
            ->assertNoJavaScriptErrors();
        expect($page->script("() => sessionStorage.getItem('checkout-xsrf-matches')"))->toBe('true');
    });

    it('checks an initialized payment after checkout cancellation or load failure', function (string $checkoutEvent, bool $dropVerification) {
        config(['services.paystack.public_key' => 'pk_test_synthetic', 'services.paystack.secret_key' => 'sk_test_synthetic']);
        $plan = PlatformPlan::create([
            'name' => 'Browser payments', 'slug' => 'browser-payments', 'price' => 0,
            'max_members' => null, 'features' => ['online_payments'], 'is_active' => true, 'sort_order' => 0,
        ]);
        $this->family->update(['platform_plan_id' => $plan->id, 'bank_code' => '058', 'account_number' => '1234567890']);
        $member = createBrowserMember($this->family);
        Contribution::factory()->forUser($member)->currentMonth()->create(['expected_amount' => 4000]);
        $page = loginBrowserAs($member);
        $page->navigate(route('pay.index', ['current_family' => $this->family->slug]))->assertSee('Pay Contributions');
        simulateFinancialContributionCheckout($page, $checkoutEvent, $dropVerification);

        $page->click('Select All')->click('button:has-text("with Paystack")');
        if ($dropVerification) {
            $page->assertPathIs("/{$this->family->slug}/pay")
                ->assertSeeIn('[role="alert"]', 'Could not confirm your payment. Check your payment history or contact your family admin before trying again.')
                ->assertEnabled('button:has-text("with Paystack")');
        } else {
            $page->assertPathIs("/{$this->family->slug}/contributions/my")
                ->assertSeeIn('[role="alert"]', 'Transaction not found.');
        }
        $page->assertNoJavaScriptErrors();
        expect($page->script("() => sessionStorage.getItem('checkout-verifications')"))->toBe('1')
            ->and($page->script("() => sessionStorage.getItem('checkout-xsrf-matches')"))->toBe('true');
    })->with([
        'cancelled' => ['onCancel', false],
        'load failure' => ['onError', false],
        'interrupted verification' => ['onCancel', true],
    ]);

    it('records a fund adjustment through the UI', function () {
        FundAdjustment::factory()->recordedBy($this->admin)->create([
            'family_id' => $this->family->id,
            'amount' => -750,
            'description' => 'Browser workflow correction',
        ]);
        $page = loginBrowserAs($this->admin);

        $page->navigate(route('fund-adjustments.index'))
            ->assertSee('Fund Adjustments')
            ->assertSee('-₦750.00')
            ->click('Record Adjustment')
            ->assertSee('Record Fund Adjustment');

        $key = $page->script('() => document.querySelector("[name=idempotency_key]")?.value');
        expect($key)->toBeString()->not->toBeEmpty();
        $page->navigate(route('fund-adjustments.index'))->click('Record Adjustment')->assertSee('Record Fund Adjustment');
        expect($page->script('() => document.querySelector("[name=idempotency_key]")?.value'))->toBe($key);

        fillBrowserFieldWithoutChange($page, '[name="amount"]', '25000');
        fillBrowserFieldWithoutChange($page, '[name="recorded_at"]', now()->format('Y-m-d'));
        fillBrowserFieldWithoutChange($page, 'input[name="description"]', 'Browser workflow opening balance');

        $page->click('form button[type="submit"]')
            ->assertSee('Browser workflow opening balance')
            ->assertNoJavaScriptErrors();

        expect(FundAdjustment::where('family_id', $this->family->id)
            ->where('recorded_by', $this->admin->id)
            ->where('description', 'Browser workflow opening balance')
            ->exists())->toBeTrue();
        $page->click('Record Adjustment')->assertSee('Record Fund Adjustment');
        expect($page->script('() => document.querySelector("[name=idempotency_key]")?.value'))->not->toBe($key);
    });

    it('schedules a member statement with a browser-local first run', function () {
        $member = createBrowserMember($this->family, [
            'name' => 'Scheduled Statement Member',
            'email' => 'scheduled-statement@example.com',
        ]);
        $page = loginBrowserAs($this->financialSecretary);

        $page->navigate(route('reports.index'))
            ->waitForEvent('networkidle')
            ->assertSee('Schedule delivery')
            ->assertDontSee('Statement member')
            ->assertPresent('[name="schedule_next_run_at"]');

        $defaultRun = $page->script(<<<'JS'
            () => {
                const field = document.querySelector('[name="schedule_next_run_at"]');
                const target = new Date(Date.now() + 60 * 60 * 1000);
                const actual = field instanceof HTMLInputElement ? field.value : null;

                return {
                    actual,
                    differenceMs: actual === null
                        ? null
                        : Math.abs(new Date(actual).getTime() - target.getTime()),
                };
            }
        JS);

        if (! is_array($defaultRun)) {
            throw new RuntimeException('Expected browser script to return the schedule run time.');
        }

        expect($defaultRun['actual'] ?? null)->toBeString()
            ->and($defaultRun['differenceMs'] ?? null)->toBeInt()->toBeLessThanOrEqual(120_000);

        $page->select('schedule_report_type', 'member_statement')
            ->wait(0.5)
            ->select('schedule_member_id', (string) $member->id)
            ->fill('schedule_name', 'Browser member statement')
            ->fill('schedule_recipients', 'member@example.com')
            ->click('Create schedule')
            ->assertSee('Browser member statement')
            ->assertNoJavaScriptErrors();

        $schedule = ReportSchedule::query()
            ->where('family_id', $this->family->id)
            ->where('name', 'Browser member statement')
            ->firstOrFail();

        expect($schedule->filters['member_id'])->toBe($member->id)
            ->and($schedule->timezone)->not->toBeEmpty();
    });

    it('sends a family invitation through the UI', function () {
        Mail::fake();

        $page = loginBrowserAs($this->financialSecretary);

        $page->navigate(route('family.invitations'))
            ->assertSee('Invitations')
            ->click('Invite Member')
            ->fill('email', 'browser-invite@example.com')
            ->select('role', 'member')
            ->click('Send Invitation')
            ->assertSee('browser-invite@example.com')
            ->assertSee('Pending')
            ->assertNoJavaScriptErrors();

        expect(FamilyInvitation::where('family_id', $this->family->id)
            ->where('email', 'browser-invite@example.com')
            ->where('invited_by', $this->financialSecretary->id)
            ->exists())->toBeTrue();
    });

    it('updates family settings and adds a contribution category through the UI', function () {
        $page = loginBrowserAs($this->admin);

        $page->navigate(route('family.settings'))
            ->assertSee('Family Settings')
            ->fill('name', 'Browser Updated Family')
            ->fill('currency', '₦')
            ->fill('due_day', '30')
            ->fill('account_name', 'Browser Account')
            ->fill('account_number', '1234567890')
            ->click('Save Changes')
            ->assertSee('Saved.')
            ->click('Add Category')
            ->fill('new-name', 'Browser Senior')
            ->fill('new-amount', '7500')
            ->click('Add')
            ->assertSee('Browser Senior')
            ->assertNoJavaScriptErrors();

        $this->family->refresh();

        expect($this->family->name)->toBe('Browser Updated Family')
            ->and($this->family->due_day)->toBe(30)
            ->and(FamilyCategory::where('family_id', $this->family->id)
                ->where('name', 'Browser Senior')
                ->where('monthly_amount', 7500)
                ->exists())->toBeTrue();
    });
});
