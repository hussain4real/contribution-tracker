<?php

use App\Models\Family;
use App\Models\User;
use App\Models\WhatsAppMessage;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config()->set('services.whatsapp', [
        'access_token' => 'test-token',
        'phone_number_id' => '1038448572690931',
        'business_account_id' => '965423126197935',
        'api_version' => 'v25.0',
        'base_url' => 'https://graph.facebook.com',
        'webhook_verify_token' => 'verify',
        'app_secret' => 'secret',
    ]);
});

describe('WhatsApp inbox authorization', function () {
    it('forbids member users from accessing the inbox', function () {
        $family = Family::factory()->create();
        $member = User::factory()->member()->employed()->create(['family_id' => $family->id]);

        $this->actingAs($member)->get('/inbox/whatsapp')->assertForbidden();
    });

    it('allows admin users to access the inbox', function () {
        $family = Family::factory()->create();
        $admin = User::factory()->admin()->create(['family_id' => $family->id]);

        $this->actingAs($admin)->get('/inbox/whatsapp')->assertOk();
    });

    it('allows financial secretary users to access the inbox', function () {
        $family = Family::factory()->create();
        $fs = User::factory()->financialSecretary()->create(['family_id' => $family->id]);

        $this->actingAs($fs)->get('/inbox/whatsapp')->assertOk();
    });
});

describe('WhatsApp inbox listing', function () {
    it('lists threads scoped to the current family only', function () {
        $family = Family::factory()->create();
        $otherFamily = Family::factory()->create();
        $admin = User::factory()->admin()->create(['family_id' => $family->id]);

        WhatsAppMessage::factory()->inbound()->create([
            'family_id' => $family->id,
            'from' => '2348012345678',
        ]);
        WhatsAppMessage::factory()->inbound()->create([
            'family_id' => $otherFamily->id,
            'from' => '2349099999999',
        ]);

        $response = $this->actingAs($admin)->get('/inbox/whatsapp');

        $response->assertOk();
        $response->assertInertia(
            fn ($page) => $page->component('Inbox/Index')
                ->has('threads', 1)
                ->where('threads.0.phone', '2348012345678')
        );
    });

    it('groups multiple messages from the same number into a single thread', function () {
        $family = Family::factory()->create();
        $admin = User::factory()->admin()->create(['family_id' => $family->id]);

        WhatsAppMessage::factory()->inbound()->count(3)->create([
            'family_id' => $family->id,
            'from' => '2348012345678',
        ]);

        $response = $this->actingAs($admin)->get('/inbox/whatsapp');

        $response->assertInertia(
            fn ($page) => $page->has('threads', 1)
                ->where('threads.0.message_count', 3)
        );
    });
});

describe('WhatsApp inbox thread', function () {
    it('shows messages in chronological order', function () {
        $family = Family::factory()->create();
        $admin = User::factory()->admin()->create(['family_id' => $family->id]);

        WhatsAppMessage::factory()->inbound()->create([
            'family_id' => $family->id,
            'from' => '2348012345678',
            'created_at' => now()->subMinutes(5),
        ]);
        WhatsAppMessage::factory()->create([
            'family_id' => $family->id,
            'direction' => 'outbound',
            'to' => '2348012345678',
            'created_at' => now()->subMinutes(2),
        ]);

        $response = $this->actingAs($admin)->get('/inbox/whatsapp/2348012345678');

        $response->assertOk();
        $response->assertInertia(
            fn ($page) => $page->component('Inbox/Thread')
                ->where('phone', '2348012345678')
                ->has('messages', 2)
                ->where('canReply', true)
        );
    });

    it('reports canReply=false when last inbound is older than 24h', function () {
        $family = Family::factory()->create();
        $admin = User::factory()->admin()->create(['family_id' => $family->id]);

        WhatsAppMessage::factory()->inbound()->create([
            'family_id' => $family->id,
            'from' => '2348012345678',
            'created_at' => now()->subDays(2),
        ]);

        $response = $this->actingAs($admin)->get('/inbox/whatsapp/2348012345678');

        $response->assertInertia(fn ($page) => $page->where('canReply', false));
    });
});

describe('WhatsApp inbox reply', function () {
    it('sends a text reply when within the 24h window', function () {
        Http::fake([
            'graph.facebook.com/*' => Http::response([
                'messages' => [['id' => 'wamid.reply']],
            ], 200),
        ]);

        $family = Family::factory()->create();
        $admin = User::factory()->admin()->create(['family_id' => $family->id]);

        WhatsAppMessage::factory()->inbound()->create([
            'family_id' => $family->id,
            'from' => '2348012345678',
            'created_at' => now()->subMinutes(5),
        ]);

        $response = $this->actingAs($admin)->post('/inbox/whatsapp/2348012345678/reply', [
            'body' => 'Thanks for your message.',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        Http::assertSent(fn ($request) => str_contains($request->url(), '/messages')
            && $request->data()['type'] === 'text'
            && $request->data()['text']['body'] === 'Thanks for your message.'
        );
    });

    it('rejects a reply when last inbound is older than 24h', function () {
        Http::fake();

        $family = Family::factory()->create();
        $admin = User::factory()->admin()->create(['family_id' => $family->id]);

        WhatsAppMessage::factory()->inbound()->create([
            'family_id' => $family->id,
            'from' => '2348012345678',
            'created_at' => now()->subDays(2),
        ]);

        $response = $this->actingAs($admin)->post('/inbox/whatsapp/2348012345678/reply', [
            'body' => 'too late',
        ]);

        $response->assertSessionHasErrors('body');
        Http::assertNothingSent();
    });

    it('forbids members from replying', function () {
        $family = Family::factory()->create();
        $member = User::factory()->member()->employed()->create(['family_id' => $family->id]);

        WhatsAppMessage::factory()->inbound()->create([
            'family_id' => $family->id,
            'from' => '2348012345678',
            'created_at' => now()->subMinutes(5),
        ]);

        $this->actingAs($member)
            ->post('/inbox/whatsapp/2348012345678/reply', ['body' => 'hi'])
            ->assertForbidden();
    });

    it('requires a body', function () {
        $family = Family::factory()->create();
        $admin = User::factory()->admin()->create(['family_id' => $family->id]);

        WhatsAppMessage::factory()->inbound()->create([
            'family_id' => $family->id,
            'from' => '2348012345678',
            'created_at' => now()->subMinutes(5),
        ]);

        $this->actingAs($admin)
            ->post('/inbox/whatsapp/2348012345678/reply', [])
            ->assertSessionHasErrors('body');
    });
});
