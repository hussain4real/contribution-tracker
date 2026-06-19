<?php

declare(strict_types=1);

use App\Features\AiAssistant;
use App\Models\Contribution;
use App\Models\Expense;
use App\Models\FamilyInvitation;
use App\Models\FundAdjustment;
use App\Models\Payment;
use App\Models\PlatformPlan;
use App\Models\User;
use App\Models\WhatsAppMessage;
use Database\Seeders\PlatformPlanSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Laravel\Pennant\Feature;

it('smokes public and guest authentication pages', function () {
    $this->seed(PlatformPlanSeeder::class);

    $family = createBrowserFamily();
    $admin = createBrowserAdmin($family);
    $member = createBrowserMember($family);
    $invitation = FamilyInvitation::factory()->create([
        'family_id' => $family->id,
        'invited_by' => $admin->id,
        'email' => 'invited@example.com',
    ]);
    $resetToken = Password::createToken($member);

    $page = visit(route('home'));
    $assertPublicGsapSurface = function (int $minimumSectionCount) use ($page): array {
        $result = $page->script(<<<'JS'
            () => ({
                heroCount: document.querySelectorAll('[data-testid="public-gsap-animation"]').length,
                heroText: (document.querySelector('[data-testid="public-gsap-animation"]')?.textContent || '').replace(/\s+/g, ' ').trim(),
                pricingPlanItems: Array.from(document.querySelectorAll('[data-testid="pricing-plan-ladder-item"]')).map((item) => ({
                    slug: item.getAttribute('data-plan-slug'),
                    name: item.getAttribute('data-plan-name'),
                    amount: item.getAttribute('data-plan-amount'),
                    memberValue: item.getAttribute('data-plan-member-value'),
                    memberCaption: item.getAttribute('data-plan-member-caption'),
                })),
                sectionCount: document.querySelectorAll('[data-gsap-section]').length,
                hoverCount: document.querySelectorAll('[data-gsap-hover]').length,
                canvasCount: document.querySelectorAll('canvas').length,
            })
        JS);

        if (! is_array($result)) {
            throw new RuntimeException('Expected browser script to return public animation measurements.');
        }

        expect($result['heroCount'] ?? 0)->toBeGreaterThanOrEqual(1)
            ->and($result['sectionCount'] ?? 0)->toBeGreaterThanOrEqual($minimumSectionCount)
            ->and($result['hoverCount'] ?? 0)->toBeGreaterThanOrEqual(1)
            ->and($result['canvasCount'] ?? 1)->toBe(0);

        return $result;
    };

    assertBrowserSmoke($page, 'Financially United');
    $assertPublicGsapSurface(3);
    navigateAndAssertBrowserSmoke($page, route('pricing'), 'Compare every plan feature');
    $pricingSurface = $assertPublicGsapSurface(2);
    $pricingHeroText = $pricingSurface['heroText'] ?? null;

    if (! is_string($pricingHeroText)) {
        throw new RuntimeException('Expected pricing hero text to be returned.');
    }

    $pricingPlanItems = $pricingSurface['pricingPlanItems'] ?? null;

    if (! is_array($pricingPlanItems)) {
        throw new RuntimeException('Expected pricing plan ladder items to be returned.');
    }

    $familyLadderPlan = collect($pricingPlanItems)->firstWhere('slug', 'family');
    $growthLadderPlan = collect($pricingPlanItems)->firstWhere('slug', 'growth');

    if (! is_array($familyLadderPlan) || ! is_array($growthLadderPlan)) {
        throw new RuntimeException('Expected Family and Growth ladder plans to be present.');
    }

    expect($pricingHeroText)
        ->toContain('Family')
        ->toContain('₦3,000')
        ->toContain('Growth')
        ->toContain('₦7,500')
        ->not->toContain('₦4k')
        ->not->toContain('₦9k');

    expect($familyLadderPlan['amount'] ?? null)
        ->toBe('₦3,000')
        ->and($familyLadderPlan['memberValue'] ?? null)->toBe('25')
        ->and($familyLadderPlan['memberCaption'] ?? null)->toBe('members')
        ->and($growthLadderPlan['amount'] ?? null)->toBe('₦7,500')
        ->and($growthLadderPlan['memberValue'] ?? null)->toBe('75')
        ->and($growthLadderPlan['memberCaption'] ?? null)->toBe('members');

    navigateAndAssertBrowserSmoke($page, route('privacy'), 'Privacy Policy');
    navigateAndAssertBrowserSmoke($page, route('terms'), 'Terms of Service');
    navigateAndAssertBrowserSmoke($page, route('data-deletion'), 'Data Deletion');
    navigateAndAssertBrowserSmoke($page, route('login'), 'Log in to your account');
    navigateAndAssertBrowserSmoke($page, route('register'), 'Create an account');
    navigateAndAssertBrowserSmoke($page, route('password.request'), 'Forgot password');
    navigateAndAssertBrowserSmoke(
        $page,
        route('password.reset', ['token' => $resetToken, 'email' => $member->email]),
        'Reset password',
    );
    navigateAndAssertBrowserSmoke(
        $page,
        route('invitations.accept', $invitation->token),
        'Join a Family',
    );
});

it('smokes authentication challenge pages', function () {
    $family = createBrowserFamily();
    $unverified = createBrowserMember($family, [
        'email' => 'unverified@example.com',
        'email_verified_at' => null,
    ]);
    $twoFactorUser = User::factory()->create([
        'family_id' => $family->id,
        'email' => 'two-factor@example.com',
        'password' => bcrypt('password'),
    ]);

    $page = visit(route('login'));

    $page->fill('email', $unverified->email)
        ->fill('password', 'password')
        ->click('@login-button')
        ->assertPathIs('/email/verify');

    assertBrowserSmoke($page, 'Verify email');

    $page->click('Log out')
        ->assertPathIs('/');

    $page->navigate(route('login'))
        ->fill('email', $twoFactorUser->email)
        ->fill('password', 'password')
        ->click('@login-button')
        ->assertPathIs('/two-factor-challenge');

    assertBrowserSmoke($page, 'Authentication Code');
});

it('smokes authenticated family pages', function () {
    $family = createBrowserFamily([
        'bank_name' => 'Test Bank',
        'bank_code' => '999',
        'account_name' => 'Browser Family',
        'account_number' => '0123456789',
    ]);
    $admin = createBrowserAdmin($family, [
        'name' => 'Browser Admin',
        'email' => 'admin-browser@example.com',
    ]);
    $member = createBrowserMember($family, [
        'name' => 'Browser Member',
        'email' => 'member-browser@example.com',
    ]);
    $financialSecretary = createBrowserFinancialSecretary($family, [
        'name' => 'Browser Secretary',
    ]);

    Feature::for($admin)->activate(AiAssistant::class);
    Feature::flushCache();
    Cache::put('paystack_banks', [], now()->addDay());

    $contribution = Contribution::factory()
        ->forUser($member)
        ->currentMonth()
        ->create();

    Payment::factory()->create([
        'contribution_id' => $contribution->id,
        'amount' => 1000,
        'recorded_by' => $financialSecretary->id,
    ]);

    Expense::factory()->recordedBy($financialSecretary)->create([
        'description' => 'Browser smoke transport',
    ]);

    FundAdjustment::factory()->recordedBy($admin)->create([
        'description' => 'Browser smoke opening balance',
    ]);

    FamilyInvitation::factory()->create([
        'family_id' => $family->id,
        'invited_by' => $admin->id,
        'email' => 'pending@example.com',
    ]);

    $phone = '2348012345678';
    WhatsAppMessage::factory()->inbound()->create([
        'family_id' => $family->id,
        'user_id' => $member->id,
        'from' => $phone,
        'body' => 'Browser smoke inbound message',
    ]);

    $admin->notifications()->create([
        'id' => (string) Str::uuid(),
        'type' => 'App\\Notifications\\ContributionReminderNotification',
        'data' => [
            'contribution_id' => $contribution->id,
            'family_name' => $family->name,
            'period_label' => $contribution->period_label,
            'amount_owed' => $contribution->balance,
            'due_date' => $contribution->due_date->toDateString(),
            'type' => 'reminder',
        ],
    ]);

    $page = loginBrowserAs($admin);

    assertBrowserSmoke($page, 'Dashboard');
    navigateAndAssertBrowserSmoke($page, route('notifications.index'), 'Notification Center');
    navigateAndAssertBrowserSmoke($page, route('inbox.whatsapp.index'), 'WhatsApp Inbox');
    navigateAndAssertBrowserSmoke($page, route('inbox.whatsapp.show', $phone), 'Conversation');
    navigateAndAssertBrowserSmoke($page, route('ai.index'), 'Conversations');
    navigateAndAssertBrowserSmoke($page, route('changelog'), "What's New");
    navigateAndAssertBrowserSmoke($page, route('members.index'), 'Family Members');
    navigateAndAssertBrowserSmoke($page, route('members.create'), 'Add New Member');
    navigateAndAssertBrowserSmoke($page, route('members.show', $member), 'Browser Member');
    navigateAndAssertBrowserSmoke($page, route('members.edit', $member), 'Edit Member');
    navigateAndAssertBrowserSmoke($page, route('contributions.index'), 'Contributions');
    navigateAndAssertBrowserSmoke($page, route('contributions.my'), 'My Contribution History');
    navigateAndAssertBrowserSmoke($page, route('contributions.show', $contribution), $contribution->period_label);
    navigateAndAssertBrowserSmoke($page, route('payments.index'), 'Record Payment');
    navigateAndAssertBrowserSmoke($page, route('payments.create', $member), 'Record Payment for Browser Member');
    navigateAndAssertBrowserSmoke($page, route('pay.index'), 'Pay Contributions');
    navigateAndAssertBrowserSmoke($page, route('subscription.index'), 'Subscription Plans');
    navigateAndAssertBrowserSmoke($page, route('expenses.index'), 'Expenses');
    navigateAndAssertBrowserSmoke($page, route('expenses.create'), 'Record Expense');
    navigateAndAssertBrowserSmoke($page, route('fund-adjustments.index'), 'Fund Adjustments');
    navigateAndAssertBrowserSmoke($page, route('reports.index'), 'Contribution Reports');
    navigateAndAssertBrowserSmoke($page, route('reports.monthly'), now()->format('F Y').' Report');
    navigateAndAssertBrowserSmoke($page, route('reports.annual'), 'Annual Report');
    navigateAndAssertBrowserSmoke($page, route('family.settings'), 'Family Settings');
    navigateAndAssertBrowserSmoke($page, route('family.invitations'), 'Invitations');
    navigateAndAssertBrowserSmoke($page, route('profile.edit'), 'Profile settings');
    navigateAndAssertBrowserSmoke($page, route('appearance.edit'), 'Appearance settings');

    $passwordConfirmPath = parse_url(route('password.confirm'), PHP_URL_PATH) ?: '/user/confirm-password';
    $securitySettingsPath = parse_url(route('security.edit'), PHP_URL_PATH) ?: '/settings/security';

    $page->navigate(route('security.edit'))
        ->assertPathIs($passwordConfirmPath)
        ->fill('password', 'password')
        ->click('@confirm-password-button')
        ->assertPathIs($securitySettingsPath)
        ->assertNoJavaScriptErrors();

    assertBrowserSmoke($page, 'Security');
    assertBrowserSmoke($page, 'Passkeys');
});

it('grows the AI chat composer before scrolling', function () {
    $family = createBrowserFamily();
    $admin = createBrowserAdmin($family, [
        'email' => 'ai-composer-browser@example.com',
    ]);

    Feature::for($admin)->activate(AiAssistant::class);
    Feature::flushCache();

    $page = loginBrowserAs($admin)
        ->navigate(route('ai.index'))
        ->assertSee('Conversations')
        ->assertNoJavaScriptErrors();

    $result = $page->script(<<<'JS'
        async () => {
            const textarea = document.querySelector('[data-testid="ai-chat-input"]');

            if (!(textarea instanceof HTMLTextAreaElement)) {
                return { found: false };
            }

            const waitForFrame = () => new Promise((resolve) => {
                window.requestAnimationFrame(() => resolve(undefined));
            });

            const measureAfterInput = async (value) => {
                textarea.value = value;
                textarea.dispatchEvent(new Event('input', { bubbles: true }));
                await Promise.resolve();
                await waitForFrame();

                return {
                    height: textarea.getBoundingClientRect().height,
                    overflowY: window.getComputedStyle(textarea).overflowY,
                };
            };

            const initial = {
                height: textarea.getBoundingClientRect().height,
            };
            const expanded = await measureAfterInput([
                'Line one',
                'Line two',
                'Line three',
                'Line four',
            ].join('\n'));
            const capped = await measureAfterInput(
                Array.from(
                    { length: 40 },
                    (_, index) => `Line ${index + 1}`,
                ).join('\n'),
            );

            return {
                found: true,
                tagName: textarea.tagName.toLowerCase(),
                initialHeight: initial.height,
                expandedHeight: expanded.height,
                cappedHeight: capped.height,
                maxHeight: Number.parseFloat(
                    window.getComputedStyle(textarea).maxHeight,
                ),
                cappedOverflowY: capped.overflowY,
            };
        }
    JS);

    if (! is_array($result)) {
        throw new RuntimeException('Expected browser script to return composer measurements.');
    }

    $initialHeight = $result['initialHeight'] ?? null;
    $expandedHeight = $result['expandedHeight'] ?? null;
    $cappedHeight = $result['cappedHeight'] ?? null;
    $maxHeight = $result['maxHeight'] ?? null;

    if (! is_int($initialHeight) && ! is_float($initialHeight)) {
        throw new RuntimeException('Expected initial composer height to be numeric.');
    }

    if (! is_int($expandedHeight) && ! is_float($expandedHeight)) {
        throw new RuntimeException('Expected expanded composer height to be numeric.');
    }

    if (! is_int($cappedHeight) && ! is_float($cappedHeight)) {
        throw new RuntimeException('Expected capped composer height to be numeric.');
    }

    if (! is_int($maxHeight) && ! is_float($maxHeight)) {
        throw new RuntimeException('Expected composer max height to be numeric.');
    }

    expect($result['found'] ?? false)->toBeTrue()
        ->and($result['tagName'] ?? null)->toBe('textarea')
        ->and((float) $expandedHeight)
        ->toBeGreaterThan((float) $initialHeight)
        ->and((float) $cappedHeight)
        ->toBeLessThanOrEqual((float) $maxHeight + 2.0)
        ->and($result['cappedOverflowY'] ?? null)->toBe('auto');
});

it('smokes platform administration pages', function () {
    $family = createBrowserFamily(['name' => 'Platform Smoke Family']);
    $superAdmin = createBrowserSuperAdmin($family, [
        'email' => 'platform-browser@example.com',
    ]);
    createBrowserMember($family, ['name' => 'Platform Smoke Member']);

    $plan = PlatformPlan::create([
        'name' => 'Browser Premium',
        'slug' => 'browser-premium',
        'price' => 5000,
        'max_members' => null,
        'paystack_plan_code' => 'PLN_browser',
        'features' => ['reports', 'online_payments'],
        'is_active' => true,
        'sort_order' => 1,
    ]);

    $this->actingAs($superAdmin);

    $page = visit('/platform/plans');

    assertBrowserSmoke($page, 'Browser Premium');
    navigateAndAssertBrowserSmoke($page, '/platform/plans/create', 'Create');
    navigateAndAssertBrowserSmoke($page, "/platform/plans/{$plan->id}/edit", 'Edit Platform Plan');
    navigateAndAssertBrowserSmoke($page, '/platform', 'Platform Overview');
    navigateAndAssertBrowserSmoke($page, '/platform/families', 'Platform Smoke Family');
    navigateAndAssertBrowserSmoke($page, "/platform/families/{$family->slug}/view", 'Platform Smoke Family');
    navigateAndAssertBrowserSmoke($page, '/platform/users', 'Platform Smoke Member');
    navigateAndAssertBrowserSmoke($page, "/platform/users/{$superAdmin->id}/view", 'platform-browser@example.com');
    navigateAndAssertBrowserSmoke($page, '/platform/feature-flags', 'AI Assistant');
});
