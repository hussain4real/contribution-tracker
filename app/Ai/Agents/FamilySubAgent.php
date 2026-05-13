<?php

namespace App\Ai\Agents;

use App\Ai\Middleware\LogPrompts;
use App\Models\User;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\CanActAsTool;
use Laravel\Ai\Contracts\HasMiddleware;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;

abstract class FamilySubAgent implements Agent, CanActAsTool, HasMiddleware, HasProviderOptions, HasTools
{
    use Promptable;

    public function __construct(public User $user) {}

    public function provider(): string
    {
        return config('ai.agent.provider', 'ollama');
    }

    public function model(): string
    {
        return config('ai.agent.model', 'llama3.2');
    }

    public function maxSteps(): int
    {
        return 6;
    }

    public function temperature(): float
    {
        return 1.0;
    }

    public function timeout(): int
    {
        return 120;
    }

    /**
     * Get the agent's middleware.
     *
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [
            new LogPrompts,
        ];
    }

    /**
     * Get provider-specific generation options.
     *
     * @return array<string, mixed>
     */
    public function providerOptions(Lab|string $provider): array
    {
        return match ($provider) {
            Lab::Ollama => [
                'top_p' => 0.95,
                'top_k' => 64,
            ],
            default => [],
        };
    }

    protected function familyContext(): string
    {
        $familyName = $this->user->family?->name ?? 'your family';
        $currency = $this->user->family?->currency ?? '₦';
        $currentDate = now()->format('F j, Y');
        $currentYear = now()->year;
        $currentMonth = now()->month;

        return <<<CONTEXT
        Family workspace: {$familyName}.
        User: {$this->user->name} (role: {$this->user->role->value}).
        Currency: {$currency}.
        Today's date: {$currentDate}.
        Current year: {$currentYear}.
        Current month: {$currentMonth}.
        CONTEXT;
    }

    protected function confirmFirstInstructions(): string
    {
        return <<<'INSTRUCTIONS'
        Confirm-first rule:
        - Never execute a write action on the first pass.
        - First call your tool without confirmed=true to produce a preview.
        - If the delegated task explicitly includes the user's confirmation and the exact action details, call the tool with confirmed=true.
        - If confirmation details are missing or ambiguous, return a concise request for confirmation instead of guessing.
        INSTRUCTIONS;
    }
}
