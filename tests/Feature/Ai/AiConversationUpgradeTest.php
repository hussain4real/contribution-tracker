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
    assert(is_object($migration) && method_exists($migration, 'up') && method_exists($migration, 'down'));
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
    /** @var object{participant_type: string, participant_id: int} $owner */
    $owner = DB::table('agent_conversations')->find($conversation);
    /** @var object{steps: string, usage: string} $row */
    $row = DB::table('agent_conversation_messages')->find($message);
    /** @var list<array{tool_calls: list<array{result: array{total: int}}>}> $steps */
    $steps = json_decode($row->steps, true);
    /** @var array<string, int> $usage */
    $usage = json_decode($row->usage, true);
    expect($owner->participant_type)->toBe($user->getMorphClass())
        ->and($owner->participant_id)->toBe($user->id)
        ->and($steps[0]['tool_calls'][0]['result'])->toBe(['total' => 12]);
    expect(TextUsage::fromArray($usage)->inputTokens)->toBe(15);
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
    /** @var object{tool_results: string, usage: string} $newRow */
    $newRow = DB::table('agent_conversation_messages')->find($newMessage);
    /** @var list<array{result: array{total: int}}> $newResults */
    $newResults = json_decode($newRow->tool_results, true);
    /** @var array<string, int> $newUsage */
    $newUsage = json_decode($newRow->usage, true);
    expect($newResults[0]['result'])->toBe(['total' => 12])
        ->and($newUsage['prompt_tokens'])->toBe(18);

    /** @var object{tool_calls: string, tool_results: string} $legacyRow */
    $legacyRow = DB::table('agent_conversation_messages')->find($message);
    /** @var object{user_id: int} $newOwner */
    $newOwner = DB::table('agent_conversations')->find($newConversation);
    expect($legacyRow->tool_calls)->toBe(json_encode([$call]))
        ->and($legacyRow->tool_results)->toBe(json_encode([$result]))
        ->and($newOwner->user_id)->toBe($user->id);
    $migration->up();
});
