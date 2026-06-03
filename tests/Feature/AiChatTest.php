<?php

declare(strict_types=1);

use App\Ai\Agents\FamilyAssistant;
use App\Features\AiAssistant;
use App\Models\Family;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Ai\Prompts\TranscriptionPrompt;
use Laravel\Ai\Providers\GeminiProvider;
use Laravel\Ai\Providers\OpenAiProvider;
use Laravel\Ai\Transcription;

beforeEach(function () {
    DB::table('features')->insert([
        'name' => AiAssistant::class,
        'scope' => '',
        'value' => 'true',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    config([
        'ai.default_for_transcription' => 'openai',
        'ai.providers.openai.key' => null,
        'ai.providers.gemini.key' => null,
        'ai.providers.mistral.key' => null,
    ]);
});

test('guests are redirected from the AI chat page', function () {
    $this->get(route('ai.index'))->assertRedirect();
});

test('authenticated users can visit the AI chat page', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('ai.index'))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Ai/Chat')
            ->has('conversations')
            ->has('messages')
        );
});

test('AI chat index handles users without a family', function () {
    $user = User::factory()->create(['family_id' => null]);

    $this->actingAs($user)
        ->get(route('ai.index'))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Ai/Chat')
            ->where('memberNames', [])
        );
});

test('AI chat index reports transcription unavailable without a configured provider', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('ai.index'))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Ai/Chat')
            ->where('transcriptionAvailable', false)
        );
});

test('AI chat index reports transcription available when only Gemini is configured', function () {
    config(['ai.providers.gemini.key' => 'gemini-key']);

    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('ai.index'))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Ai/Chat')
            ->where('transcriptionAvailable', true)
        );
});

test('AI chat index preserves Mistral transcription availability', function () {
    config(['ai.providers.mistral.key' => 'mistral-key']);

    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('ai.index'))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Ai/Chat')
            ->where('transcriptionAvailable', true)
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
        ->assertInertia(fn (Assert $page) => $page
            ->component('Ai/Chat')
            ->where('activeConversationId', $conversationId)
            ->has('messages', 1)
            ->where('messages.0.content', 'Hello there')
        );
});

test('the AI chat index clears conversation ids the user does not own', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $conversationId = (string) Str::uuid();

    DB::table('agent_conversations')->insert([
        'id' => $conversationId,
        'user_id' => $otherUser->id,
        'title' => 'Other Chat',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->actingAs($user)
        ->get(route('ai.index', ['conversation' => $conversationId]))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Ai/Chat')
            ->where('activeConversationId', null)
            ->where('messages', [])
        );
});

test('the AI chat index normalizes invalid stored activity payloads', function () {
    $user = User::factory()->create();
    $conversationId = (string) Str::uuid();

    DB::table('agent_conversations')->insert([
        'id' => $conversationId,
        'user_id' => $user->id,
        'title' => 'Invalid Payload Chat',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('agent_conversation_messages')->insert([
        'id' => (string) Str::uuid(),
        'conversation_id' => $conversationId,
        'user_id' => $user->id,
        'agent' => 'App\\Ai\\Agents\\FamilyAssistant',
        'role' => 'assistant',
        'content' => 'No valid tool activity was stored.',
        'attachments' => '[]',
        'tool_calls' => '"not an array"',
        'tool_results' => 'null',
        'usage' => '{}',
        'meta' => '{}',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->actingAs($user)
        ->get(route('ai.index', ['conversation' => $conversationId]))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Ai/Chat')
            ->where('messages.0.tool_calls', [])
            ->where('messages.0.tool_results', [])
        );
});

test('the AI chat index includes stored tool activity for assistant messages', function () {
    $user = User::factory()->create();
    $conversationId = (string) Str::uuid();

    DB::table('agent_conversations')->insert([
        'id' => $conversationId,
        'user_id' => $user->id,
        'title' => 'Tool Activity Chat',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('agent_conversation_messages')->insert([
        'id' => (string) Str::uuid(),
        'conversation_id' => $conversationId,
        'user_id' => $user->id,
        'agent' => 'App\\Ai\\Agents\\FamilyAssistant',
        'role' => 'assistant',
        'content' => 'I checked the contribution summary for you.',
        'attachments' => '[]',
        'tool_calls' => json_encode([
            [
                'id' => 'tool-call-1',
                'name' => 'GetContributionSummary',
                'arguments' => ['year' => 2026],
                'result_id' => null,
                'reasoning_summary' => [],
            ],
        ], JSON_THROW_ON_ERROR),
        'tool_results' => json_encode([
            '2' => [
                'id' => 'tool-call-1',
                'name' => 'GetContributionSummary',
                'arguments' => ['year' => 2026],
                'result' => ['period' => 'Year 2026'],
                'result_id' => null,
            ],
        ], JSON_THROW_ON_ERROR),
        'usage' => '{}',
        'meta' => '{}',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->actingAs($user)
        ->get(route('ai.index', ['conversation' => $conversationId]))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Ai/Chat')
            ->where('activeConversationId', $conversationId)
            ->where('messages.0.tool_calls.0.name', 'GetContributionSummary')
            ->where('messages.0.tool_results.0.id', 'tool-call-1')
            ->where('messages.0.tool_results.0.result.period', 'Year 2026')
        );
});

test('the AI chat index drops malformed items from stored tool activity lists', function () {
    $user = User::factory()->create();
    $conversationId = (string) Str::uuid();

    DB::table('agent_conversations')->insert([
        'id' => $conversationId,
        'user_id' => $user->id,
        'title' => 'Malformed activity',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('agent_conversation_messages')->insert([
        'id' => (string) Str::uuid(),
        'conversation_id' => $conversationId,
        'user_id' => $user->id,
        'agent' => 'App\\Ai\\Agents\\FamilyAssistant',
        'role' => 'assistant',
        'content' => 'Done',
        'attachments' => '[]',
        'tool_calls' => json_encode([
            'invalid-activity',
            ['name' => 'GetContributionSummary'],
        ], JSON_THROW_ON_ERROR),
        'tool_results' => json_encode([
            null,
            ['id' => 'tool-call-1'],
        ], JSON_THROW_ON_ERROR),
        'usage' => '{}',
        'meta' => '{}',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->actingAs($user)
        ->get(route('ai.index', ['conversation' => $conversationId]))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->where('messages.0.tool_calls.0.name', 'GetContributionSummary')
            ->where('messages.0.tool_results.0.id', 'tool-call-1')
            ->missing('messages.0.tool_calls.1')
            ->missing('messages.0.tool_results.1')
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

test('guests cannot post to the AI transcribe endpoint', function () {
    $this->postJson(route('ai.transcribe'))
        ->assertForbidden();
});

test('the transcribe endpoint validates an audio file is required', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson(route('ai.transcribe'), [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['audio']);
});

test('the transcribe endpoint rejects non-audio files', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson(route('ai.transcribe'), [
            'audio' => UploadedFile::fake()->create('document.pdf', 100, 'application/pdf'),
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['audio']);
});

test('the transcribe endpoint returns transcribed text', function () {
    config(['ai.providers.openai.key' => 'openai-key']);

    $user = User::factory()->create();

    Transcription::fake(['Hello Suleiman, how are you?']);

    $this->actingAs($user)
        ->postJson(route('ai.transcribe'), [
            'audio' => UploadedFile::fake()->create('recording.webm', 100, 'audio/webm'),
        ])
        ->assertSuccessful()
        ->assertJson(['text' => 'Hello Suleiman, how are you?']);

    Transcription::assertGenerated(fn (TranscriptionPrompt $prompt) => $prompt->provider instanceof OpenAiProvider
        && $prompt->model === 'gpt-4o-mini-transcribe'
        && $prompt->audio->mimeType() === 'audio/webm'
        && $prompt->language === 'en'
        && ! $prompt->isDiarized());
});

test('the transcribe endpoint uses Gemini when it is the only configured provider', function () {
    config(['ai.providers.gemini.key' => 'gemini-key']);

    $user = User::factory()->create();

    Transcription::fake(['Gemini transcript.']);

    $this->actingAs($user)
        ->postJson(route('ai.transcribe'), [
            'audio' => UploadedFile::fake()->create('recording.webm', 100, 'audio/webm'),
        ])
        ->assertSuccessful()
        ->assertJson(['text' => 'Gemini transcript.']);

    Transcription::assertGenerated(fn (TranscriptionPrompt $prompt) => $prompt->provider instanceof GeminiProvider
        && $prompt->model === 'gemini-3.5-flash'
        && $prompt->audio->mimeType() === 'audio/webm'
        && $prompt->language === 'en'
        && ! $prompt->isDiarized());
});

test('the transcribe endpoint respects Gemini as the configured default provider', function () {
    config([
        'ai.default_for_transcription' => 'gemini',
        'ai.providers.openai.key' => 'openai-key',
        'ai.providers.gemini.key' => 'gemini-key',
    ]);

    $user = User::factory()->create();

    Transcription::fake(['Gemini default transcript.']);

    $this->actingAs($user)
        ->postJson(route('ai.transcribe'), [
            'audio' => UploadedFile::fake()->create('recording.webm', 100, 'audio/webm'),
        ])
        ->assertSuccessful()
        ->assertJson(['text' => 'Gemini default transcript.']);

    Transcription::assertGenerated(fn (TranscriptionPrompt $prompt) => $prompt->provider instanceof GeminiProvider
        && $prompt->model === 'gemini-3.5-flash'
        && $prompt->audio->mimeType() === 'audio/webm'
        && $prompt->language === 'en'
        && ! $prompt->isDiarized());
});

test('the transcribe endpoint rejects near empty recordings before calling a provider', function () {
    config(['ai.providers.openai.key' => 'openai-key']);

    $user = User::factory()->create();

    Transcription::fake()->preventStrayTranscriptions();

    $this->actingAs($user)
        ->postJson(route('ai.transcribe'), [
            'audio' => UploadedFile::fake()->create('recording.webm', 1, 'audio/webm'),
            'duration_seconds' => 10,
            'audio_level' => 0,
            'chunk_count' => 1,
            'client_mime_type' => 'audio/webm',
        ])
        ->assertUnprocessable()
        ->assertJson([
            'message' => 'No microphone audio was captured. Check your microphone and try again.',
        ]);

    Transcription::assertNothingGenerated();
});

test('the transcribe endpoint accepts small recordings when microphone level was detected', function () {
    config(['ai.providers.openai.key' => 'openai-key']);

    $user = User::factory()->create();

    Transcription::fake(['Small but audible transcript.']);

    $this->actingAs($user)
        ->postJson(route('ai.transcribe'), [
            'audio' => UploadedFile::fake()->create('recording.webm', 1, 'audio/webm'),
            'duration_seconds' => 2,
            'audio_level' => 0.12,
            'chunk_count' => 1,
            'client_mime_type' => 'audio/webm',
        ])
        ->assertSuccessful()
        ->assertJson(['text' => 'Small but audible transcript.']);

    Transcription::assertGenerated(fn (TranscriptionPrompt $prompt) => $prompt->provider instanceof OpenAiProvider
        && $prompt->audio->mimeType() === 'audio/webm');
});

test('the transcribe endpoint returns an error when no speech is recognized', function () {
    config(['ai.providers.openai.key' => 'openai-key']);

    $user = User::factory()->create();

    Transcription::fake(['']);

    $this->actingAs($user)
        ->postJson(route('ai.transcribe'), [
            'audio' => UploadedFile::fake()->create('recording.webm', 100, 'audio/webm'),
        ])
        ->assertUnprocessable()
        ->assertJson([
            'message' => 'No speech was recognized. Check your microphone and try again.',
        ]);

    Transcription::assertGenerated(fn (TranscriptionPrompt $prompt) => $prompt->provider instanceof OpenAiProvider
        && $prompt->audio->mimeType() === 'audio/webm');
});

test('the transcribe endpoint normalizes uploaded recording mime types', function (
    string $filename,
    string $clientMimeType,
    string $providerMimeType,
) {
    config(['ai.providers.gemini.key' => 'gemini-key']);

    $user = User::factory()->create();
    $promptProvider = null;
    $promptMimeType = null;

    Transcription::fake(function (TranscriptionPrompt $prompt) use (&$promptProvider, &$promptMimeType) {
        $promptProvider = $prompt->provider::class;
        $promptMimeType = $prompt->audio->mimeType();

        return 'Normalized transcript.';
    });

    $this->actingAs($user)
        ->postJson(route('ai.transcribe'), [
            'audio' => UploadedFile::fake()->create($filename, 100, $clientMimeType),
        ])
        ->assertSuccessful()
        ->assertJson(['text' => 'Normalized transcript.']);

    expect($promptProvider)->toBe(GeminiProvider::class)
        ->and($promptMimeType)->toBe($providerMimeType);
})->with([
    'audio webm' => ['recording.webm', 'audio/webm', 'audio/webm'],
    'video webm from media recorder' => ['recording.webm', 'video/webm', 'audio/webm'],
    'mp4 container' => ['recording.mp4', 'video/mp4', 'audio/mp4'],
    'mpeg audio' => ['recording.mp3', 'audio/mpeg', 'audio/mpeg'],
    'octet-stream webm' => ['recording.webm', 'application/octet-stream', 'audio/webm'],
    'octet-stream mp4' => ['recording.mp4', 'application/octet-stream', 'audio/mp4'],
    'octet-stream mp3' => ['recording.mp3', 'application/octet-stream', 'audio/mpeg'],
    'octet-stream ogg' => ['recording.ogg', 'application/octet-stream', 'audio/ogg'],
    'octet-stream wav' => ['recording.wav', 'application/octet-stream', 'audio/wav'],
    'octet-stream flac' => ['recording.flac', 'application/octet-stream', 'audio/flac'],
    'octet-stream unknown extension' => ['recording.bin', 'application/octet-stream', 'audio/webm'],
]);
