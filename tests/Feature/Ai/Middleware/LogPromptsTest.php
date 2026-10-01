<?php

declare(strict_types=1);

use App\Ai\Agents\FamilyAssistant;
use App\Ai\Middleware\LogPrompts;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Gateway\StepResponse;
use Laravel\Ai\Gateway\StepResult;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\PendingStep;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\TextUsage;

it('logs response metadata for synchronous and streamed generation steps', function (bool $streamed) {
    $step = new PendingStep(0, false, 'ollama', 'llama3.2', null, [new Message('user', 'Summarize this month')], [], null, null);
    $response = new StepResponse('Summary ready', [], FinishReason::Stop, new TextUsage(inputTokens: 10, outputTokens: 5), new Meta('ollama', 'llama3.2'));
    $source = $streamed ? (function () use ($response) {
        yield from [];

        return $response;
    })() : $response;
    $result = new StepResult($source);

    Log::shouldReceive('info')->once()->with('AI Agent prompted', [
        'agent' => FamilyAssistant::class, 'prompt' => 'Summarize this month', 'step' => 0,
    ]);
    Log::shouldReceive('info')->once()->with('AI Agent responded', Mockery::on(
        fn (array $context): bool => $context['agent'] === FamilyAssistant::class
            && $context['provider'] === 'ollama' && $context['model'] === 'llama3.2'
            && $context['response_length'] === strlen('Summary ready') && $context['usage'] instanceof TextUsage,
    ));

    $received = (new LogPrompts(FamilyAssistant::class))->handle($step, function (PendingStep $nextStep) use ($step, $result): StepResult {
        expect($nextStep)->toBe($step);

        return $result;
    });

    expect($received)->toBe($result)->and($received->response())->toBe($response);
})->with([false, true]);
