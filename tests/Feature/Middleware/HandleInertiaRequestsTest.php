<?php

declare(strict_types=1);

use App\Http\Middleware\EnsureFamilySubscription;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Family;
use App\Models\FamilyCategory;
use App\Models\PlatformPlan;
use App\Models\User;
use App\Support\PlatformPlanCatalog;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Inertia\Testing\AssertableInertia as Assert;

test('share returns flash messages when session is available', function () {
    $this->get(route('home'))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page->has('flash'));
});

test('share returns flash values from session', function () {
    $response = $this->get(route('home'));

    $response->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->where('flash.success', null)
            ->where('flash.error', null)
            ->where('flash.warning', null)
        );
});

test('flash returns null when session is not available', function () {
    $middleware = new HandleInertiaRequests;

    $request = Request::create('/test', 'GET');
    // Do not set a session on the request

    $shared = $middleware->share($request);
    $flash = resultArray($shared, 'flash');
    $success = $flash['success'] ?? null;
    $error = $flash['error'] ?? null;
    $warning = $flash['warning'] ?? null;

    if (! is_callable($success) || ! is_callable($error) || ! is_callable($warning)) {
        throw new RuntimeException('Expected flash values to be closures.');
    }

    // Resolve the flash closures — should not throw
    expect($success())->toBeNull();
    expect($error())->toBeNull();
    expect($warning())->toBeNull()
        ->and(data_get($shared, 'auth.user'))->toBeNull()
        ->and(data_get($shared, 'auth.can'))->toBeNull()
        ->and(data_get($shared, 'family'))->toBeNull()
        ->and(data_get($shared, 'featureFlags'))->toBeNull()
        ->and(data_get($shared, 'changelogUpdate'))->toBeNull()
        ->and(data_get($shared, 'notifications'))->toBeNull();
});

test('share exposes add member permission for financial secretaries', function () {
    $financialSecretary = User::factory()->financialSecretary()->create();

    $this->actingAs($financialSecretary)
        ->get(route('members.index'))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->where('auth.can.add_members', true)
            ->where('auth.can.manage_members', false)
        );
});

test('share hides add member permission from ordinary members', function () {
    $member = User::factory()->member()->create();

    $this->actingAs($member)
        ->get(route('members.index'))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->where('auth.can.add_members', false)
            ->where('auth.can.manage_members', false)
        );
});

test('subscription data falls back when a user has no family', function () {
    $middleware = new HandleInertiaRequests;
    $method = (new ReflectionClass($middleware))->getMethod('subscriptionData');
    $user = User::factory()->make(['family_id' => null]);

    expect($method->invoke($middleware, $user))->toBe([
        'plan_name' => null,
        'member_count' => 0,
        'max_members' => null,
        'can_add_members' => false,
        'features' => [],
    ]);
});

test('share preserves family subscription columns for downstream plan gates', function () {
    $growthPlan = PlatformPlan::query()->create([
        'name' => 'Growth',
        'slug' => PlatformPlanCatalog::Growth,
        'price' => 7500,
        'max_members' => 75,
        'features' => [PlatformPlanCatalog::AiAssistant],
        'is_active' => true,
        'sort_order' => 2,
    ]);
    $family = Family::factory()->create([
        'platform_plan_id' => $growthPlan->id,
        'subscription_status' => 'active',
        'current_period_end' => '2026-09-30 12:00:00',
    ]);
    $financialSecretary = User::factory()->financialSecretary()->create([
        'family_id' => $family->id,
    ]);
    $financialSecretary = User::query()->findOrFail($financialSecretary->id);

    $request = Request::create('/'.$family->slug.'/ai', 'GET');
    $request->setUserResolver(fn (): User => $financialSecretary);

    $shared = (new HandleInertiaRequests)->share($request);
    $subscription = $shared['subscription'] ?? null;

    if (! is_callable($subscription)) {
        throw new RuntimeException('Expected subscription data to be a closure.');
    }

    $response = (new EnsureFamilySubscription)->handle(
        $request,
        fn (): Response => response('OK'),
        PlatformPlanCatalog::AiAssistant,
    );

    expect($subscription())
        ->plan_name->toBe('Growth')
        ->features->toContain(PlatformPlanCatalog::AiAssistant)
        ->and($response->getContent())->toBe('OK');
});

test('share exposes a legacy family category label when there is no active membership category', function () {
    $family = Family::factory()->create();
    $category = FamilyCategory::factory()->create([
        'family_id' => $family->id,
        'name' => 'Legacy Patron',
    ]);
    $user = User::factory()->member()->create([
        'family_id' => $family->id,
        'family_category_id' => $category->id,
    ]);

    $user->familyMemberships()->delete();
    $freshUser = User::query()->findOrFail($user->id);

    $request = Request::create('/test', 'GET');
    $request->setUserResolver(fn (): User => $freshUser);

    $shared = (new HandleInertiaRequests)->share($request);

    expect(data_get($shared, 'auth.user.category_label'))->toBe('Legacy Patron');
});
