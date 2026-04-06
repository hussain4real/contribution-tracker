<?php

namespace App\Ai\Middleware;

use Closure;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\AgentResponse;

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

        return $next($prompt)->then(function (AgentResponse $response) use ($prompt) {
            Log::info('AI Agent responded', [
                'agent' => $prompt->agent::class,
                'response_length' => Str::length($response->text ?? ''),
                'usage' => $response->usage ?? null,
            ]);
        });
    }
}
