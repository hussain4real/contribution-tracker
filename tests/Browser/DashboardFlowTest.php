<?php

declare(strict_types=1);

/**
 * T045 [US2] Browser test for dashboard navigation and member click-through
 *
 * Run with: ./vendor/bin/pest tests/Browser/ --headed
 */

use App\Models\Contribution;
use Laravel\Passkeys\Actions\VerifyPasskey;
use Mockery\MockInterface;

describe('Dashboard Flow (Browser)', function () {
    beforeEach(function () {
        $this->family = createBrowserFamily();
        $this->admin = createBrowserAdmin($this->family, [
            'email' => 'admin@test.com',
        ]);

        $this->member = createBrowserMember($this->family, [
            'name' => 'John Doe',
            'email' => 'member@test.com',
        ]);
        $this->financialSecretary = createBrowserFinancialSecretary($this->family, [
            'email' => 'financial-secretary@test.com',
        ]);

        // Create contribution for current month
        Contribution::factory()
            ->forUser($this->member)
            ->currentMonth()
            ->employed()
            ->create();
    });

    it('displays dashboard with member statuses after login', function () {
        loginBrowserAs($this->admin)
            ->assertSee('Dashboard')
            ->assertNoJavaScriptErrors();
    });

    it('shows summary cards on dashboard', function () {
        loginBrowserAs($this->admin)
            ->assertSee('Dashboard')
            ->assertSee('Total Members')
            ->assertSee('Total Collected')
            ->assertNoJavaScriptErrors();
    });

    it('loads overdue contribution details when the officer opens the modal', function () {
        Contribution::factory()->forUser($this->member)->forMonth(2025, 1)->create();

        loginBrowserAs($this->admin)
            ->click('Overdue Members')
            ->assertSee('requiring attention.')
            ->assertSee('Jan 2025')
            ->assertNoJavaScriptErrors();
    });

    it('member dashboard shows personal status', function () {
        loginBrowserAs($this->member)
            ->assertSee('Dashboard')
            ->assertSee('Your Contribution')
            ->assertNoJavaScriptErrors();
    });

    it('financial secretary login lands on the family dashboard', function () {
        loginBrowserAs($this->financialSecretary)
            ->assertSee('Dashboard')
            ->assertNoJavaScriptErrors();
    });

    it('platform administrator login lands on the platform dashboard', function () {
        $platformAdmin = createBrowserSuperAdmin(null, [
            'email' => 'platform-admin@test.com',
            'family_id' => null,
            'current_family_id' => null,
        ]);

        loginBrowserAs($platformAdmin)
            ->assertSee('Platform Overview')
            ->assertNoJavaScriptErrors();
    });

    it('passkey login completes a full page platform visit and preserves the authenticated session', function () {
        config(['d1-production.enabled' => true, 'd1-production.migration_checks' => false]);
        $platformAdmin = createBrowserSuperAdmin(null, [
            'family_id' => null,
            'current_family_id' => null,
        ]);
        $passkey = createTestPasskeyFor($platformAdmin);
        $this->mock(VerifyPasskey::class, function (MockInterface $mock) use ($passkey): void {
            $mock->shouldReceive('__invoke')->once()->andReturn($passkey);
        });

        $credential = json_encode(fakePasskeyAssertionPayload(), JSON_THROW_ON_ERROR);
        $page = visit('/login');
        $page->script(<<<JS
            () => {
                const credential = {$credential};
                const buffer = (value) => Uint8Array.from(atob(value.replace(/-/g, '+').replace(/_/g, '/')), character => character.charCodeAt(0)).buffer;
                Object.defineProperty(navigator.credentials, 'get', {value: async () => ({
                    id: credential.id,
                    rawId: buffer(credential.rawId),
                    type: 'public-key',
                    response: Object.fromEntries(Object.entries(credential.response).map(([key, value]) => [key, buffer(value)])),
                    getClientExtensionResults: () => ({}),
                    authenticatorAttachment: 'platform',
                })});
            }
            JS);

        $page->click('@passkey-login-button')
            ->assertPathIs('/platform')
            ->assertSee('Platform Overview')
            ->assertSee('Total families')
            ->assertSee('Recent families')
            ->assertSee('Recent payments')
            ->assertDontSee('Loading...')
            ->assertNoJavaScriptErrors()
            ->assertScript('document.querySelector("iframe[srcdoc]") === null')
            ->assertScript('Array.from(document.querySelectorAll("*")).every(element => Array.from(element.attributes).every(attribute => !attribute.name.startsWith("wire:poll")))');

        $asset = $page->script('async () => { const script = document.querySelector("script[src*=\"/livewire-\"]"); const url = new URL(script.src); const response = await fetch(url.pathname + url.search); return {status: response.status, javascript: response.headers.get("Content-Type").includes("javascript")}; }');
        expect($asset)->toBe(['status' => 200, 'javascript' => true]);

        $page->navigate('/platform/users')
            ->assertSee('Users')
            ->assertNoJavaScriptErrors();
    });
});
