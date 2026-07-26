<?php

declare(strict_types=1);

namespace App\Ai\Middleware;

use Closure;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\StreamableAgentResponse;

class LogPrompts
{
    /**
     * Handle the incoming prompt.
     */
    public function handle(AgentPrompt $prompt, Closure $next): mixed
    {
        Log::info('AI Agent prompted', [
            'agent' => $prompt->agent::class,
            'prompt' => $prompt->prompt,
        ]);

        $response = $next($prompt);

        if (! $response instanceof AgentResponse && ! $response instanceof StreamableAgentResponse) {
            return $response;
        }

        return $response->then(function (AgentResponse $response) use ($prompt): void {
            Log::info('AI Agent responded', [
                'agent' => $prompt->agent::class,
                'provider' => $response->meta->provider ?? null,
                'model' => $response->meta->model ?? null,
                'response_length' => Str::length($response->text ?? ''),
                'usage' => $response->usage ?? null,
            ]);
        });
    }
}
