<?php

declare(strict_types=1);

use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Models\Family;
use App\Models\PaystackTransaction;
use App\Models\PlatformPlan;
use App\Models\User;
use App\Support\PlatformPlanCatalog;
use Database\Seeders\PlatformPlanSeeder;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    config([
        'services.paystack.secret_key' => 'sk_test_secret',
        'services.paystack.public_key' => 'pk_test_public',
        'services.paystack.base_url' => 'https://api.paystack.co',
    ]);
});

it('shows subscription page for authenticated user', function () {
    PlatformPlan::create([
        'name' => 'Free',
        'slug' => 'free',
        'price' => 0,
        'max_members' => 5,
        'features' => ['basic_contributions'],
        'is_active' => true,
        'sort_order' => 0,
    ]);

    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('subscription.index'))
        ->assertSuccessful();
});

it('shows the active freemium plans with subscription card metadata', function () {
    $this->seed(PlatformPlanSeeder::class);

    $familyPlan = PlatformPlan::where('slug', PlatformPlanCatalog::Family)->firstOrFail();
    $family = Family::factory()->create([
        'platform_plan_id' => $familyPlan->id,
        'subscription_status' => 'active',
    ]);
    $admin = User::factory()->admin()->create(['family_id' => $family->id]);

    $this->actingAs($admin)
        ->get(route('subscription.index'))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Subscription/Index')
            ->has('plans', 4)
            ->where('plans.0.slug', PlatformPlanCatalog::Free)
            ->where('plans.0.price', 0)
            ->where('plans.0.max_members', 5)
            ->where('plans.1.slug', PlatformPlanCatalog::Family)
            ->where('plans.1.price', 3000)
            ->where('plans.1.max_members', 25)
            ->where('plans.1.is_recommended', true)
            ->where('plans.1.is_current', true)
            ->where('plans.2.slug', PlatformPlanCatalog::Growth)
            ->where('plans.2.price', 7500)
            ->where('plans.2.max_members', 75)
            ->where('plans.3.slug', PlatformPlanCatalog::Organization)
            ->where('plans.3.price', 20000)
            ->where('plans.3.max_members', 250)
            ->where('current_plan.name', 'Family')
            ->where('is_admin', true)
            ->where('available_features.'.PlatformPlanCatalog::WhatsappReminders, 'WhatsApp Reminders')
            ->where('available_features.'.PlatformPlanCatalog::WhatsappMessaging, 'WhatsApp Inbox & Replies')
        );
});

it('reports non admin subscription page actions through props', function () {
    $this->seed(PlatformPlanSeeder::class);

    $member = User::factory()->member()->create();

    $this->actingAs($member)
        ->get(route('subscription.index'))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Subscription/Index')
            ->where('is_admin', false)
        );
});

it('redirects guests from subscription page', function () {
    $this->get(route('subscription.index'))
        ->assertRedirect();
});

it('allows admin to subscribe to a paid monthly family plan', function () {
    Http::fake([
        'api.paystack.co/plan' => Http::response([
            'status' => true,
            'data' => ['plan_code' => 'PLN_test123'],
        ]),
        'api.paystack.co/customer' => Http::response([
            'status' => true,
            'data' => ['customer_code' => 'CUS_test123'],
        ]),
        'api.paystack.co/transaction/initialize' => Http::response([
            'status' => true,
            'data' => [
                'authorization_url' => 'https://checkout.paystack.com/test',
                'access_code' => 'access_sub',
                'reference' => 'SUB_TEST123',
            ],
        ]),
    ]);

    $plan = PlatformPlan::create([
        'name' => 'Family',
        'slug' => PlatformPlanCatalog::Family,
        'price' => 3000,
        'max_members' => 25,
        'features' => [
            PlatformPlanCatalog::BasicContributions,
            PlatformPlanCatalog::ManualPayments,
            PlatformPlanCatalog::OnlinePayments,
            PlatformPlanCatalog::Reports,
        ],
        'is_active' => true,
        'sort_order' => 1,
    ]);

    $family = Family::factory()->create();
    $admin = User::factory()->admin()->create(['family_id' => $family->id]);

    $this->actingAs($admin)
        ->postJson(route('subscription.subscribe'), [
            'plan_id' => $plan->id,
        ])
        ->assertSuccessful()
        ->assertJsonStructure(['access_code', 'authorization_url', 'reference']);

    Http::assertSent(function (Request $request): bool {
        $data = $request->data();

        return str_ends_with($request->url(), '/plan')
            && stringValue($data, 'name') === 'Family - FamilyFund'
            && intValue($data, 'amount') === 300000
            && stringValue($data, 'interval') === 'monthly';
    });

    Http::assertSent(function (Request $request): bool {
        $data = $request->data();

        return str_ends_with($request->url(), '/transaction/initialize')
            && intValue($data, 'amount') === 300000
            && stringValue($data, 'plan') === 'PLN_test123';
    });
});

it('reuses existing paystack plan and customer records when subscribing', function () {
    Http::preventStrayRequests();
    Http::fake([
        'https://api.paystack.co/transaction/initialize' => Http::response([
            'status' => true,
            'data' => [
                'authorization_url' => 'https://checkout.paystack.com/existing',
                'access_code' => 'access_existing',
                'reference' => 'SUB_EXISTING',
            ],
        ]),
    ]);

    $plan = PlatformPlan::create([
        'name' => 'Starter',
        'slug' => 'starter',
        'price' => 2000,
        'max_members' => 20,
        'features' => ['basic_contributions', 'online_payments'],
        'is_active' => true,
        'sort_order' => 1,
        'paystack_plan_code' => 'PLN_existing',
    ]);

    $family = Family::factory()->create([
        'paystack_customer_code' => 'CUS_existing',
    ]);
    $admin = User::factory()->admin()->create(['family_id' => $family->id]);

    $this->actingAs($admin)
        ->postJson(route('subscription.subscribe'), [
            'plan_id' => $plan->id,
        ])
        ->assertSuccessful()
        ->assertJsonPath('access_code', 'access_existing')
        ->assertJsonPath('authorization_url', 'https://checkout.paystack.com/existing');

    Http::assertSentCount(1);

    expect($plan->refresh()->paystack_plan_code)->toBe('PLN_existing')
        ->and($family->refresh()->paystack_customer_code)->toBe('CUS_existing');
});

it('prevents non-admin from subscribing', function () {
    $plan = PlatformPlan::create([
        'name' => 'Starter',
        'slug' => 'starter',
        'price' => 2000,
        'max_members' => 20,
        'features' => [],
        'is_active' => true,
        'sort_order' => 1,
    ]);

    $member = User::factory()->member()->create();

    $this->actingAs($member)
        ->postJson(route('subscription.subscribe'), [
            'plan_id' => $plan->id,
        ])
        ->assertForbidden();
});

it('prevents subscribing to free plan', function () {
    $freePlan = PlatformPlan::create([
        'name' => 'Free',
        'slug' => 'free',
        'price' => 0,
        'max_members' => 5,
        'features' => [],
        'is_active' => true,
        'sort_order' => 0,
    ]);

    $family = Family::factory()->create();
    $admin = User::factory()->admin()->create(['family_id' => $family->id]);

    $this->actingAs($admin)
        ->postJson(route('subscription.subscribe'), [
            'plan_id' => $freePlan->id,
        ])
        ->assertUnprocessable();
});

it('prevents subscribing to inactive retired plans', function () {
    $retiredPlan = PlatformPlan::create([
        'name' => 'Starter',
        'slug' => 'starter',
        'price' => 2000,
        'max_members' => 20,
        'features' => [PlatformPlanCatalog::BasicContributions],
        'is_active' => false,
        'sort_order' => 9,
    ]);

    $family = Family::factory()->create();
    $admin = User::factory()->admin()->create(['family_id' => $family->id]);

    $this->actingAs($admin)
        ->postJson(route('subscription.subscribe'), [
            'plan_id' => $retiredPlan->id,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('plan_id');
});

it('rejects subscription when an admin does not belong to a family', function () {
    $plan = PlatformPlan::create([
        'name' => 'Starter',
        'slug' => 'starter',
        'price' => 2000,
        'max_members' => 20,
        'features' => [],
        'is_active' => true,
        'sort_order' => 1,
    ]);
    $admin = User::factory()->admin()->create(['family_id' => null]);

    $this->actingAs($admin)
        ->postJson(route('subscription.subscribe'), [
            'plan_id' => $plan->id,
        ])
        ->assertUnprocessable()
        ->assertJson(['message' => 'You must belong to a family to subscribe.']);
});

it('returns a friendly error when subscription initialization fails', function () {
    Http::fake([
        'api.paystack.co/plan' => Http::response(['message' => 'Paystack unavailable'], 500),
    ]);

    $plan = PlatformPlan::create([
        'name' => 'Starter',
        'slug' => 'starter',
        'price' => 2000,
        'max_members' => 20,
        'features' => [],
        'is_active' => true,
        'sort_order' => 1,
    ]);
    $family = Family::factory()->create();
    $admin = User::factory()->admin()->create(['family_id' => $family->id]);

    $this->actingAs($admin)
        ->postJson(route('subscription.subscribe'), [
            'plan_id' => $plan->id,
        ])
        ->assertServerError()
        ->assertJson(['message' => 'Failed to initialize subscription. Please try again.']);
});

it('returns a friendly error when paystack plan creation returns malformed data', function () {
    Http::fake([
        'api.paystack.co/plan' => Http::response([
            'status' => true,
            'data' => null,
        ]),
    ]);

    $plan = PlatformPlan::create([
        'name' => 'Starter',
        'slug' => 'starter',
        'price' => 2000,
        'max_members' => 20,
        'features' => [],
        'is_active' => true,
        'sort_order' => 1,
    ]);
    $family = Family::factory()->create();
    $admin = User::factory()->admin()->create(['family_id' => $family->id]);

    $this->actingAs($admin)
        ->postJson(route('subscription.subscribe'), [
            'plan_id' => $plan->id,
        ])
        ->assertServerError()
        ->assertJson(['message' => 'Failed to initialize subscription. Please try again.']);
});

it('returns a friendly error when subscription checkout omits required paystack fields', function () {
    Http::fake([
        'api.paystack.co/transaction/initialize' => Http::response([
            'status' => true,
            'data' => [
                'authorization_url' => 'https://checkout.paystack.com/missing-access-code',
            ],
        ]),
    ]);

    $plan = PlatformPlan::create([
        'name' => 'Starter',
        'slug' => 'starter',
        'price' => 2000,
        'max_members' => 20,
        'features' => [],
        'is_active' => true,
        'sort_order' => 1,
        'paystack_plan_code' => 'PLN_existing',
    ]);
    $family = Family::factory()->create([
        'paystack_customer_code' => 'CUS_existing',
    ]);
    $admin = User::factory()->admin()->create(['family_id' => $family->id]);

    $this->actingAs($admin)
        ->postJson(route('subscription.subscribe'), [
            'plan_id' => $plan->id,
        ])
        ->assertServerError()
        ->assertJson(['message' => 'Failed to initialize subscription. Please try again.']);
});

it('redirects subscription callbacks without a payment reference', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('subscription.callback'))
        ->assertRedirect(route('subscription.index'))
        ->assertSessionHas('error', 'No payment reference received.');
});

it('redirects subscription callbacks for unknown payment references', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('subscription.callback', ['reference' => 'SUB_MISSING']))
        ->assertRedirect(route('subscription.index'))
        ->assertSessionHas('error', 'Payment reference not found.');
});

it('activates a subscription after a successful callback verification', function () {
    Http::fake([
        'api.paystack.co/transaction/verify/SUB_CALLBACK' => Http::response([
            'status' => true,
            'message' => 'Verification successful',
            'data' => [
                'status' => 'success',
                'amount' => 200000,
                'reference' => 'SUB_CALLBACK',
                'channel' => 'card',
                'currency' => 'NGN',
                'paid_at' => '2026-05-11T09:00:00Z',
                'metadata' => [],
            ],
        ]),
    ]);

    $plan = PlatformPlan::create([
        'name' => 'Starter',
        'slug' => 'starter',
        'price' => 2000,
        'max_members' => 20,
        'features' => [],
        'is_active' => true,
        'sort_order' => 1,
    ]);
    $family = Family::factory()->create();
    $user = User::factory()->admin()->create(['family_id' => $family->id]);
    $transaction = PaystackTransaction::create([
        'reference' => 'SUB_CALLBACK',
        'user_id' => $user->id,
        'family_id' => $family->id,
        'type' => TransactionType::Subscription,
        'amount' => 2000,
        'status' => TransactionStatus::Pending,
        'metadata' => ['plan_id' => $plan->id],
    ]);

    $this->actingAs($user)
        ->get(route('subscription.callback', ['reference' => 'SUB_CALLBACK']))
        ->assertRedirect(route('subscription.index'))
        ->assertSessionHas('success', 'Subscription activated successfully!');

    expect($transaction->refresh()->status)->toBe(TransactionStatus::Success)
        ->and($family->refresh()->platform_plan_id)->toBe($plan->id)
        ->and($family->subscription_status)->toBe('active')
        ->and($family->current_period_end?->toDateString())->toBe('2026-06-11');
});

it('handles callbacks already processed by a webhook', function () {
    Http::fake([
        'api.paystack.co/transaction/verify/SUB_ALREADY_ACTIVE' => Http::response([
            'status' => true,
            'message' => 'Verification successful',
            'data' => [
                'status' => 'success',
                'amount' => 200000,
                'reference' => 'SUB_ALREADY_ACTIVE',
                'channel' => 'card',
                'currency' => 'NGN',
                'metadata' => [],
            ],
        ]),
    ]);

    $family = Family::factory()->create();
    $user = User::factory()->admin()->create(['family_id' => $family->id]);
    PaystackTransaction::create([
        'reference' => 'SUB_ALREADY_ACTIVE',
        'user_id' => $user->id,
        'family_id' => $family->id,
        'type' => TransactionType::Subscription,
        'amount' => 2000,
        'status' => TransactionStatus::Success,
    ]);

    $this->actingAs($user)
        ->get(route('subscription.callback', ['reference' => 'SUB_ALREADY_ACTIVE']))
        ->assertRedirect(route('subscription.index'))
        ->assertSessionHas('success', 'Subscription is already active.');
});

it('shows an error when callback verification is not successful', function () {
    Http::fake([
        'api.paystack.co/transaction/verify/SUB_FAILED' => Http::response([
            'status' => true,
            'message' => 'Verification successful',
            'data' => [
                'status' => 'failed',
                'amount' => 200000,
                'reference' => 'SUB_FAILED',
                'channel' => 'card',
                'currency' => 'NGN',
                'metadata' => [],
            ],
        ]),
    ]);

    $family = Family::factory()->create();
    $user = User::factory()->admin()->create(['family_id' => $family->id]);
    PaystackTransaction::create([
        'reference' => 'SUB_FAILED',
        'user_id' => $user->id,
        'family_id' => $family->id,
        'type' => TransactionType::Subscription,
        'amount' => 2000,
        'status' => TransactionStatus::Pending,
    ]);

    $this->actingAs($user)
        ->get(route('subscription.callback', ['reference' => 'SUB_FAILED']))
        ->assertRedirect(route('subscription.index'))
        ->assertSessionHas('error', 'Payment was not successful. Please try again.');
});

it('shows an error when callback verification fails', function () {
    Http::fake([
        'api.paystack.co/transaction/verify/SUB_ERROR' => Http::response(['message' => 'Gateway down'], 500),
    ]);

    $family = Family::factory()->create();
    $user = User::factory()->admin()->create(['family_id' => $family->id]);
    PaystackTransaction::create([
        'reference' => 'SUB_ERROR',
        'user_id' => $user->id,
        'family_id' => $family->id,
        'type' => TransactionType::Subscription,
        'amount' => 2000,
        'status' => TransactionStatus::Pending,
    ]);

    $this->actingAs($user)
        ->get(route('subscription.callback', ['reference' => 'SUB_ERROR']))
        ->assertRedirect(route('subscription.index'))
        ->assertSessionHas('error', 'Could not verify payment. Please contact support if you were charged.');
});

it('prevents non-admins from cancelling subscriptions', function () {
    $member = User::factory()->member()->create();

    $this->actingAs($member)
        ->post(route('subscription.cancel'))
        ->assertForbidden();
});

it('shows an error when there is no active subscription to cancel', function () {
    $family = Family::factory()->create();
    $admin = User::factory()->admin()->create(['family_id' => $family->id]);

    $this->actingAs($admin)
        ->post(route('subscription.cancel'))
        ->assertRedirect(route('subscription.index'))
        ->assertSessionHas('error', 'No active subscription to cancel.');
});

it('cancels an active subscription', function () {
    Http::fake([
        'api.paystack.co/subscription/disable' => Http::response([
            'status' => true,
            'message' => 'Subscription disabled',
        ]),
    ]);

    $family = Family::factory()->create([
        'paystack_subscription_code' => 'SUB_test',
        'paystack_subscription_email_token' => 'token_test',
        'subscription_status' => 'active',
    ]);
    $admin = User::factory()->admin()->create(['family_id' => $family->id]);

    $this->actingAs($admin)
        ->post(route('subscription.cancel'))
        ->assertRedirect(route('subscription.index'))
        ->assertSessionHas('success');

    expect($family->refresh()->subscription_status)->toBe('cancelled');
});

it('shows an error when subscription cancellation fails', function () {
    Http::fake([
        'api.paystack.co/subscription/disable' => Http::response(['message' => 'Gateway down'], 500),
    ]);

    $family = Family::factory()->create([
        'paystack_subscription_code' => 'SUB_test',
        'paystack_subscription_email_token' => 'token_test',
        'subscription_status' => 'active',
    ]);
    $admin = User::factory()->admin()->create(['family_id' => $family->id]);

    $this->actingAs($admin)
        ->post(route('subscription.cancel'))
        ->assertRedirect(route('subscription.index'))
        ->assertSessionHas('error', 'Failed to cancel subscription. Please try again.');
});
