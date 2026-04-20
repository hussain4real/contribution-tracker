<?php

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config()->set('services.whatsapp', [
        'access_token' => 'test-token',
        'phone_number_id' => '1038448572690931',
        'business_account_id' => '965423126197935',
        'api_version' => 'v25.0',
        'base_url' => 'https://graph.facebook.com',
        'webhook_verify_token' => 'verify-token',
        'app_secret' => 'app-secret',
    ]);
});

describe('WhatsApp send code', function () {
    it('sends an OTP to the supplied phone number and caches it', function () {
        Http::fake([
            'graph.facebook.com/*' => Http::response([
                'messages' => [['id' => 'wamid.test']],
            ], 200),
        ]);

        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/settings/whatsapp/send-code', [
            'whatsapp_phone' => '+2348012345678',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('status', 'whatsapp-code-sent');

        $cached = Cache::get("whatsapp_otp:{$user->id}");

        expect($cached)->toBeArray()
            ->and($cached['phone'])->toBe('+2348012345678')
            ->and($cached['code'])->toMatch('/^\d{6}$/');

        Http::assertSent(function ($request) {
            $body = $request->data();

            return str_contains($request->url(), '/messages')
                && $body['to'] === '2348012345678'
                && $body['template']['name'] === 'verification_code';
        });
    });

    it('rejects an invalid phone number format', function () {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/settings/whatsapp/send-code', [
            'whatsapp_phone' => '08012345678',
        ]);

        $response->assertSessionHasErrors('whatsapp_phone');
        expect(Cache::has("whatsapp_otp:{$user->id}"))->toBeFalse();
    });

    it('returns an error if the WhatsApp API call fails', function () {
        Http::fake([
            'graph.facebook.com/*' => Http::response([
                'error' => ['message' => 'Bad number', 'code' => 100],
            ], 400),
        ]);

        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/settings/whatsapp/send-code', [
            'whatsapp_phone' => '+2348012345678',
        ]);

        $response->assertSessionHasErrors('whatsapp_phone');
    });
});

describe('WhatsApp verify code', function () {
    it('marks the phone as verified when the code is correct', function () {
        $user = User::factory()->withoutWhatsApp()->create();

        Cache::put("whatsapp_otp:{$user->id}", [
            'code' => '123456',
            'phone' => '+2348012345678',
        ], 600);

        $response = $this->actingAs($user)->post('/settings/whatsapp/verify', [
            'code' => '123456',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('status', 'whatsapp-verified');

        $user->refresh();

        expect($user->whatsapp_phone)->toBe('+2348012345678')
            ->and($user->whatsapp_verified_at)->not->toBeNull()
            ->and(Cache::has("whatsapp_otp:{$user->id}"))->toBeFalse();
    });

    it('rejects an invalid code', function () {
        $user = User::factory()->withoutWhatsApp()->create();

        Cache::put("whatsapp_otp:{$user->id}", [
            'code' => '123456',
            'phone' => '+2348012345678',
        ], 600);

        $response = $this->actingAs($user)->post('/settings/whatsapp/verify', [
            'code' => '999999',
        ]);

        $response->assertSessionHasErrors('code');

        expect($user->fresh()->whatsapp_verified_at)->toBeNull();
    });

    it('rejects a non-numeric or short code via validation', function () {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post('/settings/whatsapp/verify', ['code' => 'abc'])
            ->assertSessionHasErrors('code');

        $this->actingAs($user)
            ->post('/settings/whatsapp/verify', ['code' => '12345'])
            ->assertSessionHasErrors('code');
    });

    it('rejects when no code is cached', function () {
        $user = User::factory()->withoutWhatsApp()->create();

        $response = $this->actingAs($user)->post('/settings/whatsapp/verify', [
            'code' => '123456',
        ]);

        $response->assertSessionHasErrors('code');
    });
});

describe('WhatsApp destroy', function () {
    it('clears the verified phone number', function () {
        $user = User::factory()->withVerifiedWhatsApp('+2348012345678')->create();

        $response = $this->actingAs($user)->delete('/settings/whatsapp');

        $response->assertRedirect();
        $response->assertSessionHas('status', 'whatsapp-removed');

        $user->refresh();

        expect($user->whatsapp_phone)->toBeNull()
            ->and($user->whatsapp_verified_at)->toBeNull();
    });
});
