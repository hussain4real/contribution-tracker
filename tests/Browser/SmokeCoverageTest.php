<?php

use App\Features\AiAssistant;
use App\Models\Contribution;
use App\Models\Expense;
use App\Models\FamilyInvitation;
use App\Models\FundAdjustment;
use App\Models\Payment;
use App\Models\PlatformPlan;
use App\Models\User;
use App\Models\WhatsAppMessage;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Laravel\Pennant\Feature;

it('smokes public and guest authentication pages', function () {
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

    assertBrowserSmoke($page, 'Financially United');
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
    navigateAndAssertBrowserSmoke($page, route('user-password.edit'), 'Update password');
    navigateAndAssertBrowserSmoke($page, route('appearance.edit'), 'Appearance settings');

    $passwordConfirmPath = parse_url(route('password.confirm'), PHP_URL_PATH) ?: '/user/confirm-password';
    $twoFactorSettingsPath = parse_url(route('two-factor.show'), PHP_URL_PATH) ?: '/settings/two-factor';

    $page->navigate(route('two-factor.show'))
        ->assertPathIs($passwordConfirmPath)
        ->fill('password', 'password')
        ->click('@confirm-password-button')
        ->assertPathIs($twoFactorSettingsPath)
        ->assertNoJavaScriptErrors();

    assertBrowserSmoke($page, 'Two-factor authentication');
    navigateAndAssertBrowserSmoke($page, route('passkeys.show'), 'Passkeys');
});

it('smokes platform administration pages', function () {
    $family = createBrowserFamily(['name' => 'Platform Smoke Family']);
    $superAdmin = createBrowserSuperAdmin($family, [
        'email' => 'platform-browser@example.com',
    ]);
    createBrowserMember($family, ['name' => 'Platform Smoke Member']);

    PlatformPlan::create([
        'name' => 'Browser Premium',
        'slug' => 'browser-premium',
        'price' => 5000,
        'max_members' => null,
        'paystack_plan_code' => 'PLN_browser',
        'features' => ['reports', 'online_payments'],
        'is_active' => true,
        'sort_order' => 1,
    ]);

    $page = loginBrowserAs($superAdmin);

    navigateAndAssertBrowserSmoke($page, route('platform.dashboard'), 'Platform Overview');
    navigateAndAssertBrowserSmoke($page, route('platform.families'), 'All Families');
    navigateAndAssertBrowserSmoke($page, route('platform.families.show', $family), 'Platform Smoke Family');
    navigateAndAssertBrowserSmoke($page, route('platform.users'), 'All Users');
    navigateAndAssertBrowserSmoke($page, route('platform.plans'), 'Platform Plans');
    navigateAndAssertBrowserSmoke($page, route('platform.feature-flags'), 'Feature Flags');
});
