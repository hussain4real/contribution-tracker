<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Ai\Responses\Data\TextUsage;
use Laravel\Ai\Storage\DatabaseConversationStore;

it('backfills legacy conversation ownership and tool history and preserves rollback data', function () {
    $user = User::factory()->create();
    $migration = require database_path('migrations/2026_10_01_120000_upgrade_ai_conversations_for_sdk_v1.php');
    $migration->down();
    $conversation = (string) Str::uuid7();
    $message = (string) Str::uuid7();
    $call = ['id' => 'call-one', 'name' => 'Summary', 'arguments' => []];
    $result = [...$call, 'result' => ['total' => 12]];
    DB::table('agent_conversations')->insert([
        'id' => $conversation, 'user_id' => $user->id, 'title' => 'Legacy conversation',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('agent_conversation_messages')->insert([
        'id' => $message, 'conversation_id' => $conversation, 'user_id' => $user->id,
        'agent' => 'SummaryAgent', 'role' => 'assistant', 'content' => 'Summary ready',
        'attachments' => '[]', 'tool_calls' => json_encode([$call]), 'tool_results' => json_encode([$result]),
        'usage' => json_encode(['prompt_tokens' => 10, 'completion_tokens' => 5, 'cache_read_input_tokens' => 2, 'cache_write_input_tokens' => 3, 'reasoning_tokens' => 1]), 'meta' => '[]', 'created_at' => now(), 'updated_at' => now(),
    ]);

    $migration->up();
    $owner = DB::table('agent_conversations')->find($conversation);
    $row = DB::table('agent_conversation_messages')->find($message);
    expect($owner->participant_type)->toBe($user->getMorphClass())
        ->and($owner->participant_id)->toBe($user->id)
        ->and(json_decode($row->steps, true)[0]['tool_calls'][0]['result'])->toBe(['total' => 12]);
    expect(TextUsage::fromArray(json_decode($row->usage, true))->inputTokens)->toBe(15);
    $store = new DatabaseConversationStore;
    expect($store->conversationBelongsTo($conversation, $user->getMorphClass(), $user->id))->toBeTrue()
        ->and($store->conversationBelongsTo($conversation, 'another-model', $user->id))->toBeFalse()
        ->and($store->getLatestConversationMessages($conversation, 10))->toHaveCount(3);
    $newConversation = $store->storeConversation($user->getMorphClass(), $user->id, 'New conversation');
    $newMessage = (string) Str::uuid7();
    DB::table('agent_conversation_messages')->insert([
        'id' => $newMessage, 'conversation_id' => $newConversation,
        'participant_type' => $user->getMorphClass(), 'participant_id' => $user->id,
        'agent' => 'SummaryAgent', 'role' => 'assistant', 'content' => 'New summary',
        'attachments' => '[]', 'steps' => json_encode([['content' => 'New summary', 'tool_calls' => [$result]]]),
        'status' => 'completed', 'usage' => json_encode(['input_tokens' => 20, 'output_tokens' => 7, 'cache_read_input_tokens' => 2, 'reasoning_tokens' => 1]), 'meta' => '[]', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $migration->down();
    expect(json_decode(DB::table('agent_conversation_messages')->find($newMessage)->tool_results, true)[0]['result'])->toBe(['total' => 12])
        ->and(json_decode(DB::table('agent_conversation_messages')->find($newMessage)->usage, true)['prompt_tokens'])->toBe(18);

    expect(DB::table('agent_conversation_messages')->find($message)->tool_calls)->toBe(json_encode([$call]))
        ->and(DB::table('agent_conversation_messages')->find($message)->tool_results)->toBe(json_encode([$result]))
        ->and(DB::table('agent_conversations')->find($newConversation)->user_id)->toBe($user->id);
    $migration->up();
});
