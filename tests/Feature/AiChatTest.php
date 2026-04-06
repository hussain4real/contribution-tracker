<?php

use App\Ai\Agents\FamilyAssistant;
use App\Models\Family;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

test('guests are redirected from the AI chat page', function () {
    $this->get(route('ai.index'))->assertRedirect();
});

test('authenticated users can visit the AI chat page', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('ai.index'))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->component('Ai/Chat')
            ->has('conversations')
            ->has('messages')
        );
});

test('guests cannot post to the AI chat stream', function () {
    $this->post(route('ai.chat'), ['message' => 'Hello'])
        ->assertRedirect();
});

test('the AI chat stream validates a message is required', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson(route('ai.chat'), ['message' => ''])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['message']);
});

test('the AI chat stream validates message max length', function () {
    $user = User::factory()->create();

    FamilyAssistant::fake();

    $this->actingAs($user)
        ->postJson(route('ai.chat'), ['message' => str_repeat('a', 5001)])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['message']);
});

test('authenticated users can stream an AI chat response', function () {
    $family = Family::factory()->create();
    $user = User::factory()->create(['family_id' => $family->id]);

    FamilyAssistant::fake(['Hello! I can help you with your family contributions.']);

    $response = $this->actingAs($user)
        ->post(route('ai.chat'), ['message' => 'Hello']);

    $response->assertSuccessful();
});

test('users can continue an existing conversation', function () {
    $family = Family::factory()->create();
    $user = User::factory()->create(['family_id' => $family->id]);

    $conversationId = (string) Str::uuid();

    DB::table('agent_conversations')->insert([
        'id' => $conversationId,
        'user_id' => $user->id,
        'title' => 'Test Conversation',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    FamilyAssistant::fake(['Continuing the conversation.']);

    $response = $this->actingAs($user)
        ->post(route('ai.chat'), [
            'message' => 'Tell me more',
            'conversation_id' => $conversationId,
        ]);

    $response->assertSuccessful();
});

test('users cannot access another user conversation and get a fresh one instead', function () {
    $family = Family::factory()->create();
    $user = User::factory()->create(['family_id' => $family->id]);
    $otherUser = User::factory()->create(['family_id' => $family->id]);

    $conversationId = (string) Str::uuid();

    DB::table('agent_conversations')->insert([
        'id' => $conversationId,
        'user_id' => $otherUser->id,
        'title' => 'Other User Conversation',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    FamilyAssistant::fake();

    $this->actingAs($user)
        ->post(route('ai.chat'), [
            'message' => 'Hello',
            'conversation_id' => $conversationId,
        ])
        ->assertSuccessful();
});

test('users can rename their conversation', function () {
    $user = User::factory()->create();
    $conversationId = (string) Str::uuid();

    DB::table('agent_conversations')->insert([
        'id' => $conversationId,
        'user_id' => $user->id,
        'title' => 'Old Title',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->actingAs($user)
        ->patch(route('ai.conversations.rename', $conversationId), [
            'title' => 'New Title',
        ])
        ->assertRedirect();

    $this->assertDatabaseHas('agent_conversations', [
        'id' => $conversationId,
        'title' => 'New Title',
    ]);
});

test('users cannot rename another user conversation', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $conversationId = (string) Str::uuid();

    DB::table('agent_conversations')->insert([
        'id' => $conversationId,
        'user_id' => $otherUser->id,
        'title' => 'Other Title',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->actingAs($user)
        ->patch(route('ai.conversations.rename', $conversationId), [
            'title' => 'Hacked Title',
        ])
        ->assertNotFound();

    $this->assertDatabaseHas('agent_conversations', [
        'id' => $conversationId,
        'title' => 'Other Title',
    ]);
});

test('users can delete their conversation', function () {
    $user = User::factory()->create();
    $conversationId = (string) Str::uuid();

    DB::table('agent_conversations')->insert([
        'id' => $conversationId,
        'user_id' => $user->id,
        'title' => 'To Delete',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('agent_conversation_messages')->insert([
        'id' => (string) Str::uuid(),
        'conversation_id' => $conversationId,
        'user_id' => $user->id,
        'agent' => 'App\\Ai\\Agents\\FamilyAssistant',
        'role' => 'user',
        'content' => 'Hello',
        'attachments' => '[]',
        'tool_calls' => '[]',
        'tool_results' => '[]',
        'usage' => '{}',
        'meta' => '{}',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->actingAs($user)
        ->delete(route('ai.conversations.destroy', $conversationId))
        ->assertRedirect();

    $this->assertDatabaseMissing('agent_conversations', ['id' => $conversationId]);
    $this->assertDatabaseMissing('agent_conversation_messages', ['conversation_id' => $conversationId]);
});

test('users cannot delete another user conversation', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $conversationId = (string) Str::uuid();

    DB::table('agent_conversations')->insert([
        'id' => $conversationId,
        'user_id' => $otherUser->id,
        'title' => 'Other Conversation',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->actingAs($user)
        ->delete(route('ai.conversations.destroy', $conversationId))
        ->assertNotFound();

    $this->assertDatabaseHas('agent_conversations', ['id' => $conversationId]);
});

test('the AI chat index loads messages for an active conversation', function () {
    $user = User::factory()->create();
    $conversationId = (string) Str::uuid();

    DB::table('agent_conversations')->insert([
        'id' => $conversationId,
        'user_id' => $user->id,
        'title' => 'My Chat',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('agent_conversation_messages')->insert([
        'id' => (string) Str::uuid(),
        'conversation_id' => $conversationId,
        'user_id' => $user->id,
        'agent' => 'App\\Ai\\Agents\\FamilyAssistant',
        'role' => 'user',
        'content' => 'Hello there',
        'attachments' => '[]',
        'tool_calls' => '[]',
        'tool_results' => '[]',
        'usage' => '{}',
        'meta' => '{}',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->actingAs($user)
        ->get(route('ai.index', ['conversation' => $conversationId]))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->component('Ai/Chat')
            ->where('activeConversationId', $conversationId)
            ->has('messages', 1)
            ->where('messages.0.content', 'Hello there')
        );
});

test('rename validates title is required', function () {
    $user = User::factory()->create();
    $conversationId = (string) Str::uuid();

    DB::table('agent_conversations')->insert([
        'id' => $conversationId,
        'user_id' => $user->id,
        'title' => 'Test',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->actingAs($user)
        ->patch(route('ai.conversations.rename', $conversationId), ['title' => ''])
        ->assertSessionHasErrors(['title']);
});
