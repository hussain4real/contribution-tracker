<?php

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\LazyLoadingViolationException;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Passport\Contracts\AuthorizationViewResponse;

it('configures the whatsapp notification rate limiter', function () {
    config(['services.whatsapp.rate_limit_per_minute' => 0]);

    $limit = RateLimiter::limiter('whatsapp-notifications')((object) []);

    expect($limit->maxAttempts)->toBe(1)
        ->and($limit->decaySeconds)->toBe(60)
        ->and($limit->key)->toBe('whatsapp-notifications');
});

it('configures the custom passport authorization view callback', function () {
    $response = app(AuthorizationViewResponse::class);
    $reflection = new ReflectionObject($response);
    $view = $reflection->getProperty('view')->getValue($response);

    $result = $view([
        'client' => (object) ['id' => 1, 'name' => 'MCP Client'],
        'user' => User::factory()->make(['email' => 'member@example.com']),
        'scopes' => [],
        'request' => request(),
        'authToken' => 'token',
    ]);

    expect($result->name())->toBe('mcp.authorize');
});

it('throws lazy loading violations outside production', function () {
    Model::automaticallyEagerLoadRelationships(false);
    Model::preventLazyLoading(true);

    $user = User::factory()->create();
    $freshUser = User::query()->whereKey($user->id)->firstOrFail();
    $freshUser->preventsLazyLoading = true;

    expect(fn () => $freshUser->family)->toThrow(LazyLoadingViolationException::class);

    Model::preventLazyLoading(false);
    Model::automaticallyEagerLoadRelationships();
});

it('logs lazy loading violations in production', function () {
    app()->detectEnvironment(fn (): string => 'production');
    Model::automaticallyEagerLoadRelationships(false);
    Model::preventLazyLoading(true);

    $user = User::factory()->create();
    $freshUser = User::query()->whereKey($user->id)->firstOrFail();
    $freshUser->preventsLazyLoading = true;

    expect($freshUser->family)->not->toBeNull();

    app()->detectEnvironment(fn (): string => 'testing');
    Model::preventLazyLoading(false);
    Model::automaticallyEagerLoadRelationships();
});
