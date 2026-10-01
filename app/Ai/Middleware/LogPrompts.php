<?php

declare(strict_types=1);

namespace App\Ai\Middleware;

use Closure;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Gateway\StepResponse;
use Laravel\Ai\Gateway\StepResult;
use Laravel\Ai\PendingStep;

class LogPrompts
{
    public function __construct(private string $agentClass) {}

    /** @param Closure(PendingStep): StepResult $next */
    public function handle(PendingStep $step, Closure $next): StepResult
    {
        $prompt = collect($step->messages)->last(fn ($message): bool => $message->role->value === 'user')->content ?? '';

        Log::info('AI Agent prompted', [
            'agent' => $this->agentClass,
            'prompt' => $prompt,
            'step' => $step->number,
        ]);

        return $next($step)->then(function (StepResponse $response) use ($step): void {
            Log::info('AI Agent responded', [
                'agent' => $this->agentClass,
                'provider' => $response->meta->provider,
                'model' => $response->meta->model,
                'response_length' => mb_strlen($response->text),
                'usage' => $response->usage,
                'step' => $step->number,
            ]);
        });
    }
}
