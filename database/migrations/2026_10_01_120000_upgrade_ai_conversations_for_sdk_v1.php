<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Ai\Migrations\AiMigration;

return new class extends AiMigration
{
    public function up(): void
    {
        $schema = Schema::connection($this->getConnection());
        $messages = Config::string('ai.conversations.tables.messages', 'agent_conversation_messages');
        $conversations = Config::string('ai.conversations.tables.conversations', 'agent_conversations');
        $db = DB::connection($this->getConnection());
        $this->assertUnambiguousToolHistory($db, $messages);

        $db->table($messages)->orderBy('id')->chunk(100, function ($rows): void {
            foreach ($rows as $row) {
                /** @var object{id: string, tool_calls: ?string, tool_results: ?string, meta: ?string, usage: ?string, content: string, steps: ?string} $row */
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
                /** @var object{id: string, tool_calls: ?string, tool_results: ?string, meta: ?string, usage: ?string, content: string, steps: ?string} $row */
                /** @var array<string, int> $usage */
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
                /** @var Collection<int, object{id: string, tool_calls: ?string, tool_results: ?string, meta: ?string, usage: ?string, content: string, steps: ?string}> $rows */
                $rows = $db->table($messages)->where('conversation_id', $conversation->conversation_id)->where('role', 'assistant')->orderBy('id')->get();
                /** @var Collection<string, array{id: string, result?: mixed, denied?: bool, failed?: bool}> $results */
                $results = $rows->flatMap(fn (object $row) => $this->decoded($row->tool_results))->keyBy('id');

                foreach ($rows as $row) {
                    /** @var object{id: string, tool_calls: ?string, tool_results: ?string, meta: ?string, usage: ?string, content: string, steps: ?string} $row */
                    /** @var array{reasoning?: string} $meta */
                    $meta = $this->decoded($row->meta);
                    /** @var list<array{id: string, name: string, arguments: array<string, mixed>}> $legacyCalls */
                    $legacyCalls = $this->decoded($row->tool_calls);
                    $calls = array_values(collect($legacyCalls)->map(function (array $call) use ($results): array {
                        $result = $results->get($call['id']);

                        return $result === null ? $call : [...$call, 'result' => $result['result'] ?? null, ...array_filter([
                            'denied' => $result['denied'] ?? false,
                            'failed' => $result['failed'] ?? false,
                        ])];
                    })->all());
                    $content = $row->content;
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
        $messages = Config::string('ai.conversations.tables.messages', 'agent_conversation_messages');
        $conversations = Config::string('ai.conversations.tables.conversations', 'agent_conversations');
        $db = DB::connection($this->getConnection());
        $legacyResults = $this->assertUnambiguousToolHistory($db, $messages, true);

        if ($db->table($messages)->where('status', '!=', 'completed')->exists()) {
            throw new RuntimeException('Resolve incomplete AI turns before rolling back conversation storage.');
        }

        // Complete the read-only rollback admission scan before updating any row or schema.
        $db->table($messages)->orderBy('id')->chunk(100, function ($rows) use ($legacyResults): void {
            foreach ($rows as $row) {
                /** @var object{conversation_id: string, steps: ?string, tool_calls: ?string, tool_results: ?string, meta: ?string, usage: ?string, content: string} $row */
                $this->decoded($row->tool_calls);
                $this->decoded($row->tool_results);
                $this->decoded($row->usage);
                $this->assertLegacyRollbackHistory($row->steps, $row->tool_calls, $row->tool_results, $row->meta, $row->content, $legacyResults['conversation:'.$row->conversation_id] ?? []);
            }
        });

        foreach ([$messages, $conversations] as $table) {
            if ($schema->hasColumn($table, 'user_id')) {
                if ($db->table($table)->whereNotNull('participant_type')->where('participant_type', '!=', (new User)->getMorphClass())->exists()) {
                    throw new RuntimeException('Non-user AI participants cannot be represented by legacy conversation storage.');
                }
            }
        }

        foreach ([$messages, $conversations] as $table) {
            if ($schema->hasColumn($table, 'user_id')) {
                $db->table($table)->update(['user_id' => DB::raw('participant_id')]);
            }
        }

        $db->table($messages)->orderBy('id')->chunk(100, function ($rows) use ($db, $messages) {
            foreach ($rows as $row) {
                /** @var object{id: string, tool_calls: ?string, tool_results: ?string, meta: ?string, usage: ?string, content: string, steps: ?string} $row */
                // Preserve original historical payloads; reconstruct only messages written by the new SDK.
                if ($row->tool_calls !== null && $row->tool_results !== null) {
                    continue;
                }
                $calls = [];
                $results = [];
                /** @var list<array{tool_calls?: list<array{id: string, name: string, arguments: array<string, mixed>, result?: mixed, denied?: bool, failed?: bool}>}> $storedSteps */
                $storedSteps = $this->decoded($row->steps);
                foreach ($storedSteps as $step) {
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
                /** @var array<string, int> $usage */
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

    /** @return array<string, array<string, array<string, mixed>>> */
    protected function assertUnambiguousToolHistory(Connection $db, string $messages, bool $useSteps = false): array
    {
        $seenCalls = [];
        $results = [];
        $db->table($messages)->orderBy('id')->chunk(100, function ($rows) use ($useSteps, &$seenCalls, &$results): void {
            foreach ($rows as $row) {
                /** @var object{conversation_id: string, tool_calls: ?string, tool_results: ?string, steps?: ?string} $row */
                $conversation = 'conversation:'.$row->conversation_id;
                $calls = $this->decoded($row->tool_calls);
                if ($useSteps) {
                    $calls = [];
                    foreach ($this->decoded($row->steps ?? null) as $step) {
                        if (! is_array($step) || ! is_array($step['tool_calls'] ?? [])) {
                            throw new RuntimeException('AI conversation steps have an invalid structure.');
                        }
                        foreach ($step['tool_calls'] ?? [] as $call) {
                            $calls[] = $call;
                        }
                    }
                }
                foreach ($calls as $call) {
                    if (! is_array($call) || ! is_string($call['id'] ?? null)) {
                        throw new RuntimeException('AI conversation tool calls require string identifiers.');
                    }
                    $key = 'call:'.$call['id'];
                    if (isset($seenCalls[$conversation][$key])) {
                        throw new RuntimeException('Preserve ambiguous repeated AI tool-call identifiers before changing conversation storage.');
                    }
                    $seenCalls[$conversation][$key] = true;
                }
                foreach ($this->decoded($row->tool_results) as $result) {
                    if (! is_array($result) || ! is_string($result['id'] ?? null)) {
                        throw new RuntimeException('AI conversation tool results require string identifiers.');
                    }
                    $key = 'call:'.$result['id'];
                    $namedResult = [];
                    foreach ($result as $name => $value) {
                        if (! is_string($name)) {
                            throw new RuntimeException('AI conversation tool result attributes must use named keys.');
                        }
                        $namedResult[$name] = $value;
                    }
                    if (isset($results[$conversation][$key]) && $results[$conversation][$key] !== $namedResult) {
                        throw new RuntimeException('Preserve conflicting AI tool results before changing conversation storage.');
                    }
                    $results[$conversation][$key] = $namedResult;
                }
            }
        });

        return $results;
    }

    /** @param array<string, array<string, mixed>> $legacyResultsById */
    protected function assertLegacyRollbackHistory(?string $stepsJson, ?string $legacyCalls, ?string $legacyResults, ?string $metaJson, string $content, array $legacyResultsById): void
    {
        $steps = $this->decoded($stepsJson);
        $meta = $this->decoded($metaJson);
        $normalizedSteps = [];
        $calls = [];
        foreach ($steps as $index => $step) {
            if (! is_array($step)) {
                throw new RuntimeException('AI conversation steps must contain arrays.');
            }
            if (($step['provider_tool_calls'] ?? []) !== [] || ($step['replay_blocks'] ?? []) !== []) {
                throw new RuntimeException('Preserve SDK v1 provider-tool and replay history before rolling back conversation storage.');
            }
            if (array_diff(array_keys($step), ['content', 'tool_calls', 'reasoning', 'replay_blocks', 'provider_tool_calls']) !== []) {
                throw new RuntimeException('Preserve SDK v1 step attributes before rolling back conversation storage.');
            }
            $stepCalls = $step['tool_calls'] ?? [];
            $stepContent = $step['content'] ?? '';
            $reasoning = $step['reasoning'] ?? '';
            if (! is_array($stepCalls) || ! array_is_list($stepCalls) || ! is_string($stepContent) || ! is_string($reasoning)) {
                throw new RuntimeException('AI conversation steps have an invalid structure.');
            }
            $normalizedCalls = [];
            foreach ($stepCalls as $call) {
                if (! is_array($call)) {
                    throw new RuntimeException('AI conversation tool calls must contain arrays.');
                }
                foreach (['denied', 'failed'] as $flag) {
                    if (array_key_exists($flag, $call) && ($call[$flag] !== true || ! array_key_exists('result', $call))) {
                        throw new RuntimeException('Preserve SDK v1 tool-call status before rolling back conversation storage.');
                    }
                }
                $namedCall = [];
                foreach ($call as $key => $value) {
                    if (! is_string($key)) {
                        throw new RuntimeException('AI conversation tool call attributes must use named keys.');
                    }
                    $namedCall[$key] = $value;
                }
                $calls[] = $namedCall;
                $normalizedCalls[] = $namedCall;
            }
            $normalizedSteps[] = $this->step($stepContent, $normalizedCalls, $reasoning);
            if ($reasoning !== '' && ($legacyCalls === null || $legacyResults === null || $reasoning !== ($meta['reasoning'] ?? ''))) {
                throw new RuntimeException('Preserve SDK v1 reasoning history before rolling back conversation storage.');
            }
            if ($stepContent !== '' && ($index !== array_key_last($steps) || $stepContent !== $content)) {
                throw new RuntimeException('Preserve intermediate SDK v1 responses before rolling back conversation storage.');
            }
        }
        if ($legacyCalls !== null && $legacyResults !== null) {
            $archivedCalls = [];
            foreach ($this->decoded($legacyCalls) as $call) {
                if (! is_array($call) || ! is_string($call['id'] ?? null)) {
                    throw new RuntimeException('Legacy AI calls require string identifiers.');
                }
                $result = $legacyResultsById['call:'.$call['id']] ?? null;
                $archivedCalls[] = $result === null ? $call : [...$call, 'result' => $result['result'] ?? null, ...array_filter([
                    'denied' => $result['denied'] ?? false,
                    'failed' => $result['failed'] ?? false,
                ])];
            }
            if ($calls !== $archivedCalls) {
                throw new RuntimeException('Preserve SDK v1 changes to archived tool history before rolling back conversation storage.');
            }
        }
        if ($normalizedSteps !== []) {
            $legacyReasoning = $meta['reasoning'] ?? '';
            if (! is_string($legacyReasoning)) {
                throw new RuntimeException('Legacy AI reasoning must contain text.');
            }
            $reconstructed = $calls !== [] && $content !== ''
                ? [$this->step('', $calls), $this->step($content, [], $legacyReasoning)]
                : [$this->step($content, $calls, $legacyReasoning)];
            if ($normalizedSteps !== $reconstructed) {
                throw new RuntimeException('Preserve SDK v1 step boundaries before rolling back conversation storage.');
            }
        }
    }

    /**
     * @param  list<array<string, mixed>>  $calls
     * @return array<string, mixed>
     */
    protected function step(string $content, array $calls = [], string $reasoning = ''): array
    {
        return ['content' => $content, 'tool_calls' => $calls, 'reasoning' => $reasoning, 'replay_blocks' => [], 'provider_tool_calls' => []];
    }

    /** @return array<array-key, mixed> */
    protected function decoded(?string $json): array
    {
        $decoded = json_decode($json ?? '[]', true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($decoded)) {
            throw new RuntimeException('AI conversation JSON must contain an array.');
        }

        return $decoded;
    }
};
