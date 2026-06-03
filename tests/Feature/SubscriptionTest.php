<?php

declare(strict_types=1);

use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Models\Family;
use App\Models\PaystackTransaction;
use App\Models\PlatformPlan;
use App\Models\User;
use Illuminate\Support\Facades\Http;

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

it('redirects guests from subscription page', function () {
    $this->get(route('subscription.index'))
        ->assertRedirect();
});

it('allows admin to subscribe to a paid plan', function () {
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
        'name' => 'Starter',
        'slug' => 'starter',
        'price' => 2000,
        'max_members' => 20,
        'features' => ['basic_contributions', 'online_payments'],
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
