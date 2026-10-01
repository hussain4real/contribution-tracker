<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Ai\Migrations\AiMigration;

return new class extends AiMigration
{
    public function up(): void
    {
        $schema = Schema::connection($this->getConnection());
        $messages = config('ai.conversations.tables.messages', 'agent_conversation_messages');
        $conversations = config('ai.conversations.tables.conversations', 'agent_conversations');
        $db = DB::connection($this->getConnection());

        $db->table($messages)->orderBy('id')->chunk(100, function ($rows): void {
            foreach ($rows as $row) {
                $this->decoded($row->tool_calls);
                $this->decoded($row->tool_results);
                $this->decoded($row->meta);
                $this->decoded($row->usage);
            }
        });

        foreach ([$conversations, $messages] as $table) {
            if (! $schema->hasColumn($table, 'participant_type')) {
                $schema->table($table, function (Blueprint $blueprint) {
                    $blueprint->string('participant_type')->nullable();
                    $blueprint->unsignedBigInteger('participant_id')->nullable();
                });
                $db->table($table)->whereNotNull('user_id')->update([
                    'participant_type' => (new User)->getMorphClass(),
                    'participant_id' => DB::raw('user_id'),
                ]);
            }
        }

        $schema->table($messages, function (Blueprint $blueprint) {
            $blueprint->longText('steps')->nullable();
            $blueprint->string('status', 25)->default('completed');
            $blueprint->text('tool_calls')->nullable()->change();
            $blueprint->text('tool_results')->nullable()->change();
        });

        $db->table($messages)->orderBy('id')->chunkById(100, function ($rows) use ($db, $messages): void {
            foreach ($rows as $row) {
                $usage = $this->decoded($row->usage);

                if (array_key_exists('prompt_tokens', $usage)) {
                    $usage['input_tokens'] ??= $usage['prompt_tokens'] + ($usage['cache_read_input_tokens'] ?? 0) + ($usage['cache_write_input_tokens'] ?? 0);
                    $usage['output_tokens'] ??= ($usage['completion_tokens'] ?? 0) + ($usage['reasoning_tokens'] ?? 0);
                    $db->table($messages)->where('id', $row->id)->update(['usage' => json_encode($usage, JSON_THROW_ON_ERROR)]);
                }
            }
        });

        $db->table($messages)->where('role', '!=', 'assistant')->update(['steps' => '[]']);
        $db->table($messages)->select('conversation_id')->distinct()->orderBy('conversation_id')->chunk(100, function ($conversations) use ($db, $messages) {
            foreach ($conversations as $conversation) {
                $rows = $db->table($messages)->where('conversation_id', $conversation->conversation_id)->where('role', 'assistant')->orderBy('id')->get();
                $results = $rows->flatMap(fn (object $row) => $this->decoded($row->tool_results))->keyBy('id');

                foreach ($rows as $row) {
                    $meta = $this->decoded($row->meta);
                    $calls = collect($this->decoded($row->tool_calls))->map(function (array $call) use ($results): array {
                        $result = $results->get($call['id'] ?? '');

                        return $result === null ? $call : [...$call, 'result' => $result['result'] ?? null, ...array_filter([
                            'denied' => $result['denied'] ?? false,
                            'failed' => $result['failed'] ?? false,
                        ])];
                    })->all();
                    $content = (string) $row->content;
                    $steps = $calls !== [] && $content !== ''
                        ? [$this->step('', $calls), $this->step($content, [], $meta['reasoning'] ?? '')]
                        : [$this->step($content, $calls, $meta['reasoning'] ?? '')];
                    $db->table($messages)->where('id', $row->id)->update(['steps' => json_encode($steps, JSON_THROW_ON_ERROR)]);
                }
            }
        });

        $schema->table($messages, function (Blueprint $blueprint) {
            $blueprint->longText('steps')->nullable(false)->change();
            $blueprint->index(['participant_type', 'participant_id', 'agent'], 'ai_participant_agent_index');
        });
        $schema->table($conversations, function (Blueprint $blueprint) {
            $blueprint->index(['participant_type', 'participant_id', 'updated_at'], 'ai_participant_updated_index');
        });
    }

    public function down(): void
    {
        $schema = Schema::connection($this->getConnection());
        $messages = config('ai.conversations.tables.messages', 'agent_conversation_messages');
        $conversations = config('ai.conversations.tables.conversations', 'agent_conversations');
        $db = DB::connection($this->getConnection());

        if ($db->table($messages)->where('status', '!=', 'completed')->exists()) {
            throw new RuntimeException('Resolve incomplete AI turns before rolling back conversation storage.');
        }

        foreach ([$messages, $conversations] as $table) {
            if ($schema->hasColumn($table, 'user_id')) {
                if ($db->table($table)->whereNotNull('participant_type')->where('participant_type', '!=', (new User)->getMorphClass())->exists()) {
                    throw new RuntimeException('Non-user AI participants cannot be represented by legacy conversation storage.');
                }
                $db->table($table)->update(['user_id' => DB::raw('participant_id')]);
            }
        }

        $db->table($messages)->orderBy('id')->chunk(100, function ($rows) use ($db, $messages) {
            foreach ($rows as $row) {
                // Preserve original historical payloads; reconstruct only messages written by the new SDK.
                if ($row->tool_calls !== null && $row->tool_results !== null) {
                    continue;
                }
                $calls = [];
                $results = [];
                foreach ($this->decoded($row->steps) as $step) {
                    foreach ($step['tool_calls'] ?? [] as $call) {
                        if (array_key_exists('result', $call)) {
                            $results[] = ['id' => $call['id'], 'name' => $call['name'], 'result' => $call['result'], ...array_filter([
                                'denied' => $call['denied'] ?? false,
                                'failed' => $call['failed'] ?? false,
                            ])];
                        }
                        unset($call['result'], $call['denied'], $call['failed']);
                        $calls[] = $call;
                    }
                }
                $usage = $this->decoded($row->usage);

                if (array_key_exists('input_tokens', $usage)) {
                    $usage['prompt_tokens'] ??= $usage['input_tokens'] - ($usage['cache_read_input_tokens'] ?? 0) - ($usage['cache_write_input_tokens'] ?? 0);
                    $usage['completion_tokens'] ??= ($usage['output_tokens'] ?? 0) - ($usage['reasoning_tokens'] ?? 0);
                }

                $db->table($messages)->where('id', $row->id)->update([
                    'usage' => json_encode($usage, JSON_THROW_ON_ERROR),
                    'tool_calls' => json_encode($calls, JSON_THROW_ON_ERROR),
                    'tool_results' => json_encode($results, JSON_THROW_ON_ERROR),
                ]);
            }
        });

        $schema->table($messages, function (Blueprint $blueprint) {
            $blueprint->dropIndex('ai_participant_agent_index');
            $blueprint->dropColumn(['steps', 'status', 'participant_type', 'participant_id']);
            $blueprint->text('tool_calls')->nullable(false)->change();
            $blueprint->text('tool_results')->nullable(false)->change();
        });
        $schema->table($conversations, function (Blueprint $blueprint) {
            $blueprint->dropIndex('ai_participant_updated_index');
            $blueprint->dropColumn(['participant_type', 'participant_id']);
        });
    }

    /** @return array<string, mixed> */
    protected function step(string $content, array $calls = [], string $reasoning = ''): array
    {
        return ['content' => $content, 'tool_calls' => $calls, 'reasoning' => $reasoning, 'replay_blocks' => [], 'provider_tool_calls' => []];
    }

    /** @return array<string, mixed> */
    protected function decoded(?string $json): array
    {
        $decoded = json_decode($json ?? '[]', true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($decoded)) {
            throw new RuntimeException('AI conversation JSON must contain an array.');
        }

        return $decoded;
    }
};
