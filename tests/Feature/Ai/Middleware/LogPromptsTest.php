<?php

declare(strict_types=1);

use App\Ai\Agents\FamilyAssistant;
use App\Ai\Middleware\LogPrompts;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;

it('logs prompts and response metadata', function () {
    $agent = new FamilyAssistant(User::factory()->create());
    $provider = typedMock(TextProvider::class);
    $prompt = new AgentPrompt(
        agent: $agent,
        prompt: 'Summarize this month',
        attachments: [],
        provider: $provider,
        model: 'llama3.2',
    );
    $response = new AgentResponse(
        invocationId: 'invocation-1',
        text: 'Summary ready',
        usage: new Usage(promptTokens: 10, completionTokens: 5),
        meta: new Meta(provider: 'ollama', model: 'llama3.2'),
    );

    Log::shouldReceive('info')
        ->once()
        ->with('AI Agent prompted', [
            'agent' => FamilyAssistant::class,
            'prompt' => 'Summarize this month',
        ]);
    Log::shouldReceive('info')
        ->once()
        ->with('AI Agent responded', Mockery::on(
            fn (array $context): bool => $context['agent'] === FamilyAssistant::class
                && $context['provider'] === 'ollama'
                && $context['model'] === 'llama3.2'
                && $context['response_length'] === strlen('Summary ready')
                && $context['usage'] instanceof Usage,
        ));

    $result = (new LogPrompts)->handle(
        $prompt,
        fn (AgentPrompt $receivedPrompt): AgentResponse => tap($response, function () use ($receivedPrompt, $prompt) {
            expect($receivedPrompt)->toBe($prompt);
        }),
    );

    expect($result)->toBe($response);
});

it('returns non-agent responses without attaching response logging', function () {
    $agent = new FamilyAssistant(User::factory()->create());
    $provider = typedMock(TextProvider::class);
    $prompt = new AgentPrompt(
        agent: $agent,
        prompt: 'Return raw',
        attachments: [],
        provider: $provider,
        model: 'llama3.2',
    );

    Log::shouldReceive('info')
        ->once()
        ->with('AI Agent prompted', [
            'agent' => FamilyAssistant::class,
            'prompt' => 'Return raw',
        ]);

    $result = (new LogPrompts)->handle(
        $prompt,
        fn (AgentPrompt $receivedPrompt): string => tap('raw-response', function () use ($receivedPrompt, $prompt) {
            expect($receivedPrompt)->toBe($prompt);
        }),
    );

    expect($result)->toBe('raw-response');
});
