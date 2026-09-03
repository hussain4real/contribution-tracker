<?php

declare(strict_types=1);

use App\Actions\ScoreFamilyPaymentRisk;
use App\Enums\Role;
use App\Features\PredictiveAnalytics;
use App\Models\PlatformPlan;
use App\Support\PlatformPlanCatalog;
use Illuminate\Support\Facades\Storage;
use Laravel\Pennant\Feature;

describe('Payment risk insights (Browser)', function () {
    beforeEach(function () {
        Storage::fake('local');
        Storage::fake('public');

        $plan = PlatformPlan::query()->create([
            'name' => 'Risk Browser Plan',
            'slug' => 'risk-browser-plan',
            'price' => 7500,
            'max_members' => 75,
            'features' => [
                PlatformPlanCatalog::BasicContributions,
                PlatformPlanCatalog::ManualPayments,
                PlatformPlanCatalog::Reports,
            ],
            'is_active' => true,
            'sort_order' => 98,
        ]);

        $this->family = createBrowserFamily([
            'name' => 'Risk Browser Family',
            'platform_plan_id' => $plan->id,
            'subscription_status' => 'active',
        ]);
        $this->admin = createBrowserAdmin($this->family, [
            'name' => 'Risk Review Officer',
            'email' => 'risk-browser@example.com',
        ]);

        Feature::for($this->admin)->activate(PredictiveAnalytics::class);
        Feature::flushCache();
    });

    it('allows an admin to review the honest unavailable state responsively in dark mode', function () {
        $page = loginBrowserAs($this->admin);

        $page->navigate(route('payment-risk.index', ['current_family' => $this->family->slug]))
            ->resize(390, 844)
            ->assertSee('Payment Risk Insights')
            ->assertSee('Advisory insight, not an automated decision')
            ->assertSee('This is not a credit score')
            ->assertSee('No validated model is active')
            ->assertMissing('button[aria-label="Refresh payment risk insights for the selected period"]')
            ->assertScript(<<<'JS'
                () => {
                    localStorage.setItem('appearance', 'dark');
                    document.documentElement.classList.add('dark');
                    const period = document.querySelector('#risk-period');
                    period?.focus();

                    return document.documentElement.classList.contains('dark')
                        && document.activeElement === period
                        && document.documentElement.scrollWidth <= document.documentElement.clientWidth + 1;
                }
            JS)
            ->assertNoAccessibilityIssues()
            ->assertNoJavaScriptErrors()
            ->assertNoSmoke();
    });

    it('allows a financial secretary to open payment risk insights from officer navigation', function () {
        $financialSecretary = createBrowserFinancialSecretary($this->family, [
            'name' => 'Risk Financial Secretary',
            'email' => 'risk-secretary@example.com',
        ]);
        Feature::for($financialSecretary)->activate(PredictiveAnalytics::class);
        Feature::flushCache();

        $page = loginBrowserAs($financialSecretary);

        $page->assertSee('Payment Risk')
            ->navigate(route('payment-risk.index', ['current_family' => $this->family->slug]))
            ->assertSee('Payment Risk Insights')
            ->assertSee('Officer decision support')
            ->assertNoAccessibilityIssues()
            ->assertNoSmoke();
    });

    it('keeps payment risk navigation and insights unavailable to members', function () {
        $member = createBrowserMember($this->family, [
            'name' => 'Risk Ordinary Member',
            'email' => 'risk-ordinary-member@example.com',
        ]);
        Feature::for($member)->activate(PredictiveAnalytics::class);
        Feature::flushCache();

        $page = loginBrowserAs($member);

        $page->assertDontSee('Payment Risk')
            ->navigate(route('payment-risk.index', ['current_family' => $this->family->slug]))
            ->assertSee('403')
            ->assertNoJavaScriptErrors();
    });

    it('renders populated and insufficient-history advisories responsively and remains tenant isolated', function () {
        installActiveTestPaymentRiskModel();
        $member = createBrowserMember($this->family, [
            'name' => 'Synthetic Review Member',
            'email' => 'risk-member@example.com',
        ]);
        $earlyHistoryMember = createBrowserMember($this->family, [
            'name' => 'Early History Member',
            'email' => 'risk-early-history@example.com',
        ]);
        $period = now()->toImmutable()->startOfMonth();
        createMatureRiskHistory($this->family, $member, 6);
        scoreTestContribution($this->family, $member, $period);
        scoreTestContribution($this->family, $earlyHistoryMember, $period);
        app(ScoreFamilyPaymentRisk::class)->handle($this->family, $period->year, $period->month);

        $otherFamily = createBrowserFamily([
            'name' => 'Other Browser Family',
            'platform_plan_id' => $this->family->platform_plan_id,
            'subscription_status' => 'active',
        ]);
        $this->admin->ensureFamilyMembership($otherFamily, Role::Admin);

        $page = loginBrowserAs($this->admin);

        $page->navigate(route('payment-risk.index', [
            'current_family' => $this->family->slug,
            'period' => $period->format('Y-m'),
        ]))
            ->resize(390, 844)
            ->assertSee('Model ready')
            ->assertSee('test-model-v1')
            ->assertSee('Synthetic Review Member')
            ->assertSee('Experimental')
            ->assertSee('6 prior mature periods')
            ->assertSee('Early History Member')
            ->assertSee('Unavailable history')
            ->assertSee('0 prior mature periods')
            ->assertSee('Fewer than three mature periods were recorded before the scoring cutoff.')
            ->assertSee('Refresh insights')
            ->assertScript(<<<'JS'
                () => {
                    localStorage.setItem('appearance', 'dark');
                    document.documentElement.classList.add('dark');

                    return document.documentElement.classList.contains('dark')
                        && document.documentElement.scrollWidth <= document.documentElement.clientWidth + 1;
                }
            JS)
            ->assertNoAccessibilityIssues()
            ->assertNoJavaScriptErrors()
            ->assertNoSmoke()
            ->navigate(route('payment-risk.index', [
                'current_family' => $otherFamily->slug,
                'period' => $period->format('Y-m'),
            ]))
            ->assertSee('No contributions match these filters')
            ->assertDontSee('Synthetic Review Member')
            ->assertNoJavaScriptErrors();
    });

    it('applies period and band filters and keeps refresh navigation safe and keyboard focusable', function () {
        installActiveTestPaymentRiskModel();
        $member = createBrowserMember($this->family, [
            'name' => 'Filtered Review Member',
            'email' => 'risk-filtered-member@example.com',
        ]);
        $earlyHistoryMember = createBrowserMember($this->family, [
            'name' => 'Filtered Early History Member',
            'email' => 'risk-filtered-early@example.com',
        ]);
        $period = now()->toImmutable()->startOfMonth();
        createMatureRiskHistory($this->family, $member, 6);
        scoreTestContribution($this->family, $member, $period);
        scoreTestContribution($this->family, $earlyHistoryMember, $period);
        app(ScoreFamilyPaymentRisk::class)->handle($this->family, $period->year, $period->month);

        $page = loginBrowserAs($this->admin);
        $riskPath = "/{$this->family->slug}/payment-risk";

        $page->navigate(route('payment-risk.index', [
            'current_family' => $this->family->slug,
            'period' => $period->format('Y-m'),
        ]))
            ->assertPathIs($riskPath)
            ->assertQueryStringHas('period', $period->format('Y-m'))
            ->assertScript(<<<'JS'
                () => {
                    const refresh = document.querySelector(
                        'button[aria-label="Refresh payment risk insights for the selected period"]',
                    );
                    refresh?.focus();

                    return document.activeElement === refresh;
                }
            JS)
            ->click('button[aria-label="Refresh payment risk insights for the selected period"]')
            ->assertSee('Payment-risk refresh completed: 0 new, 2 unchanged.')
            ->assertPathIs($riskPath)
            ->assertQueryStringHas('period', $period->format('Y-m'))
            ->assertNoJavaScriptErrors()
            ->refresh()
            ->assertPathIs($riskPath)
            ->assertQueryStringHas('period', $period->format('Y-m'))
            ->assertSee('Filtered Review Member')
            ->assertScript(<<<'JS'
                () => {
                    const band = document.querySelector('#risk-band');
                    band?.focus();

                    return document.activeElement === band;
                }
            JS)
            ->select('band', 'routine_review')
            ->click('Apply filters')
            ->assertQueryStringHas('period', $period->format('Y-m'))
            ->assertQueryStringHas('band', 'routine_review')
            ->assertSee('Filtered Review Member')
            ->assertDontSee('Filtered Early History Member');

        $emptyPeriod = $period->addMonth()->format('Y-m');

        $page->fill('period', $emptyPeriod)
            ->click('Apply filters')
            ->assertQueryStringHas('period', $emptyPeriod)
            ->assertQueryStringHas('band', 'routine_review')
            ->assertSee('No contributions match these filters')
            ->assertNoAccessibilityIssues()
            ->assertNoSmoke();
    });
});
