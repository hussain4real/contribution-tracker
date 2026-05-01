<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

function webPushPayload(array $overrides = []): array
{
    return array_replace_recursive([
        'endpoint' => 'https://updates.push.services.mozilla.com/wpush/v2/test-endpoint',
        'keys' => [
            'p256dh' => 'test-public-key',
            'auth' => 'test-auth-token',
        ],
        'contentEncoding' => 'aes128gcm',
    ], $overrides);
}

beforeEach(function () {
    config()->set('webpush.vapid.public_key', 'test-public-vapid-key');
    config()->set('webpush.vapid.private_key', 'test-private-vapid-key');
});

describe('web push subscriptions', function () {
    it('requires authentication to store a subscription', function () {
        $response = $this->post(route('web-push.subscription.store'), webPushPayload());

        $response->assertRedirect();
    });

    it('stores a valid browser subscription for the authenticated user', function () {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('web-push.subscription.store'), webPushPayload());

        $response->assertRedirect();

        $subscription = $user->pushSubscriptions()->first();

        expect($subscription)->not->toBeNull()
            ->and($subscription->endpoint)->toBe('https://updates.push.services.mozilla.com/wpush/v2/test-endpoint')
            ->and($subscription->public_key)->toBe('test-public-key')
            ->and($subscription->auth_token)->toBe('test-auth-token')
            ->and($subscription->content_encoding)->toBe('aes128gcm');
    });

    it('updates an existing endpoint instead of creating a duplicate', function () {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('web-push.subscription.store'), webPushPayload());
        $this->actingAs($user)->post(route('web-push.subscription.store'), webPushPayload([
            'keys' => [
                'p256dh' => 'updated-public-key',
                'auth' => 'updated-auth-token',
            ],
        ]));

        expect($user->pushSubscriptions()->count())->toBe(1);

        $subscription = $user->pushSubscriptions()->first();

        expect($subscription->public_key)->toBe('updated-public-key')
            ->and($subscription->auth_token)->toBe('updated-auth-token');
    });

    it('deletes the current users browser subscription', function () {
        $user = User::factory()->create();
        $user->updatePushSubscription(
            'https://updates.push.services.mozilla.com/wpush/v2/test-endpoint',
            'test-public-key',
            'test-auth-token',
            'aes128gcm',
        );

        $response = $this->actingAs($user)->delete(route('web-push.subscription.destroy'), [
            'endpoint' => 'https://updates.push.services.mozilla.com/wpush/v2/test-endpoint',
        ]);

        $response->assertRedirect();

        expect($user->pushSubscriptions()->exists())->toBeFalse();
    });

    it('does not delete another users subscription for the supplied endpoint', function () {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $otherUser->updatePushSubscription(
            'https://updates.push.services.mozilla.com/wpush/v2/other-endpoint',
            'other-public-key',
            'other-auth-token',
            'aes128gcm',
        );

        $response = $this->actingAs($user)->delete(route('web-push.subscription.destroy'), [
            'endpoint' => 'https://updates.push.services.mozilla.com/wpush/v2/other-endpoint',
        ]);

        $response->assertRedirect();

        expect($otherUser->pushSubscriptions()->exists())->toBeTrue();
    });

    it('rejects invalid subscription payloads', function () {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('web-push.subscription.store'), [
            'endpoint' => 'not-a-url',
            'keys' => [
                'p256dh' => '',
            ],
        ]);

        $response->assertSessionHasErrors(['endpoint', 'keys.auth']);
    });
});

describe('web push shared props', function () {
    it('shares only safe web push configuration with authenticated pages', function () {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('profile.edit'));

        $response->assertSuccessful()
            ->assertInertia(fn (Assert $page) => $page
                ->where('webPush.enabled', true)
                ->where('webPush.publicKey', 'test-public-vapid-key')
                ->where('webPush.subscribed', false)
                ->missing('webPush.privateKey')
            );
    });

    it('marks web push as subscribed when the user has a subscription', function () {
        $user = User::factory()->create();
        $user->updatePushSubscription(
            'https://updates.push.services.mozilla.com/wpush/v2/test-endpoint',
            'test-public-key',
            'test-auth-token',
            'aes128gcm',
        );

        $response = $this->actingAs($user)->get(route('profile.edit'));

        $response->assertSuccessful()
            ->assertInertia(fn (Assert $page) => $page
                ->where('webPush.subscribed', true)
            );
    });

    it('does not expose a public key when web push is not configured', function () {
        config()->set('webpush.vapid.public_key', null);

        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('profile.edit'));

        $response->assertSuccessful()
            ->assertInertia(fn (Assert $page) => $page
                ->where('webPush.enabled', false)
                ->where('webPush.publicKey', null)
                ->missing('webPush.privateKey')
            );
    });
});
