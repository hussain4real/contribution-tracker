<?php

declare(strict_types=1);

use App\Http\Controllers\AiChatController;
use App\Http\Controllers\SubscriptionController;
use App\Http\Middleware\EnsureFamilyIsNotSuspended;
use App\Http\Middleware\EnsureFamilyMembership;
use App\Http\Middleware\EnsureFamilySubscription;
use App\Http\Middleware\EnsurePasswordIsNotTemporary;
use App\Http\Middleware\EnsurePlatformSuperAdmin;
use App\Http\Responses\LoginResponse;
use App\Http\Responses\TwoFactorLoginResponse;
use App\Http\Responses\VerifyEmailResponse;
use App\Models\Family;
use App\Models\PlatformPlan;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Route as IlluminateRoute;
use Illuminate\Support\Facades\Route as RouteFacade;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * @param  array<string, object|string|null>  $routeParameters
 * @param  array<string, string>  $server
 */
function starterKitCoverageRequest(?User $user = null, array $routeParameters = [], array $server = []): Request
{
    $request = Request::create('/coverage', 'GET', server: $server);

    if ($user instanceof User) {
        $request->setUserResolver(fn (): User => $user);
    }

    $route = new IlluminateRoute(['GET'], '/coverage', fn () => null);
    $route->bind($request);

    foreach ($routeParameters as $name => $value) {
        $route->setParameter($name, $value);
    }

    $request->setRouteResolver(fn (): IlluminateRoute => $route);

    return $request;
}

function starterKitCoverageCurrentFamily(): Family
{
    $family = app('current-family');

    if (! $family instanceof Family) {
        throw new RuntimeException('Expected current-family to be bound to a family.');
    }

    return $family;
}

test('family middleware handles resolved family route models and missing family parameters', function () {
    $family = Family::factory()->create(['name' => 'Resolved Family']);
    $user = User::factory()->admin()->create(['family_id' => $family->id]);

    $response = (new EnsureFamilyMembership)->handle(
        starterKitCoverageRequest($user, ['current_family' => $family]),
        fn (Request $request) => response('', 204),
    );

    expect($response->getStatusCode())->toBe(204);
    expect(starterKitCoverageCurrentFamily()->is($family))->toBeTrue();

    expect(fn () => (new EnsureFamilyMembership)->handle(
        starterKitCoverageRequest($user),
        fn (Request $request) => response('', 204),
    ))->toThrow(HttpException::class);
});

test('suspension middleware accepts a resolved family route model', function () {
    $family = Family::factory()->create(['name' => 'Active Family']);
    $user = User::factory()->member()->create(['family_id' => $family->id]);

    $response = (new EnsureFamilyIsNotSuspended)->handle(
        starterKitCoverageRequest($user, ['current_family' => $family]),
        fn (Request $request) => response('', 204),
    );

    expect($response->getStatusCode())->toBe(204);
    expect(starterKitCoverageCurrentFamily()->is($family))->toBeTrue();
});

test('subscription middleware passes unauthenticated requests through', function () {
    $response = (new EnsureFamilySubscription)->handle(
        starterKitCoverageRequest(),
        fn (Request $request) => response('', 204),
    );

    expect($response->getStatusCode())->toBe(204);
});

test('temporary password middleware returns a json lock response', function () {
    $user = User::factory()->create([
        'must_change_password_at' => now(),
    ]);

    $response = (new EnsurePasswordIsNotTemporary)->handle(
        starterKitCoverageRequest($user, server: ['HTTP_ACCEPT' => 'application/json']),
        fn (Request $request) => response('', 204),
    );

    if (! $response instanceof JsonResponse) {
        throw new RuntimeException('Expected temporary password middleware to return JSON.');
    }

    expect($response->getStatusCode())->toBe(423);
    expect($response->getData(true))->toBe([
        'message' => 'You must change your temporary password before continuing.',
    ]);
});

test('platform admin middleware rejects non platform administrators', function () {
    $user = User::factory()->member()->create();

    expect(fn () => (new EnsurePlatformSuperAdmin)->handle(
        starterKitCoverageRequest($user),
        fn (Request $request) => response('', 204),
    ))->toThrow(HttpException::class);
});

test('auth responses return json payloads when requested by clients', function () {
    $request = Request::create('/login', 'POST', server: ['HTTP_ACCEPT' => 'application/json']);

    $loginResponse = (new LoginResponse)->toResponse($request);
    $twoFactorResponse = (new TwoFactorLoginResponse)->toResponse($request);
    $verifyEmailResponse = (new VerifyEmailResponse)->toResponse($request);

    if (! $loginResponse instanceof JsonResponse) {
        throw new RuntimeException('Expected login response to be JSON.');
    }

    expect($loginResponse->getStatusCode())->toBe(200);
    expect($loginResponse->getData(true))->toBe(['two_factor' => false]);
    expect($twoFactorResponse->getStatusCode())->toBe(204);
    expect($verifyEmailResponse->getStatusCode())->toBe(204);
});

test('auth redirects switch to the first available family when legacy fields are empty', function () {
    $family = Family::factory()->create(['name' => 'Fallback Family']);
    $user = User::factory()->member()->create([
        'family_id' => null,
        'current_family_id' => null,
    ]);

    $user->ensureFamilyMembership($family);

    $request = Request::create('/login', 'POST');
    $request->setLaravelSession(app('session.store'));
    $request->setUserResolver(fn (): User => $user);

    $response = (new LoginResponse)->toResponse($request);
    $freshUser = User::query()->findOrFail($user->id);

    if (! $response instanceof RedirectResponse) {
        throw new RuntimeException('Expected login response to redirect.');
    }

    expect($response->getTargetUrl())->toContain("/{$family->slug}/dashboard");
    expect($freshUser->current_family_id)->toBe($family->id);
    expect($freshUser->family_id)->toBe($family->id);
});

test('subscription controller returns json when an admin has no family', function () {
    RouteFacade::post('/coverage/subscription', [SubscriptionController::class, 'subscribe'])
        ->middleware(['web', 'auth']);

    $plan = PlatformPlan::create([
        'name' => 'Coverage Paid',
        'slug' => 'coverage-paid',
        'price' => 5000,
        'max_members' => 20,
        'features' => [],
        'is_active' => true,
        'sort_order' => 1,
    ]);
    $user = User::factory()->admin()->create([
        'family_id' => null,
        'current_family_id' => null,
    ]);

    $this->actingAs($user)
        ->postJson('/coverage/subscription', ['plan_id' => $plan->id])
        ->assertStatus(422)
        ->assertJson([
            'message' => 'You must belong to a family to subscribe.',
        ]);
});

test('ai chat page exposes no member names when the user has no family', function () {
    RouteFacade::get('/coverage/ai-chat', [AiChatController::class, 'index'])
        ->middleware(['web', 'auth']);

    $user = User::factory()->member()->create([
        'family_id' => null,
        'current_family_id' => null,
    ]);

    $this->actingAs($user)
        ->get('/coverage/ai-chat')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Ai/Chat')
            ->where('memberNames', [])
        );
});
