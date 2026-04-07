<?php

namespace App\Ai\Agents;

use App\Ai\Middleware\LogPrompts;
use App\Ai\Tools\GetContributionSummary;
use App\Ai\Tools\GetExpenseSummary;
use App\Ai\Tools\GetFundBalance;
use App\Ai\Tools\GetMemberOverview;
use App\Models\User;
use Laravel\Ai\Attributes\MaxSteps;
use Laravel\Ai\Attributes\Temperature;
use Laravel\Ai\Attributes\Timeout;
use Laravel\Ai\Concerns\RemembersConversations;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasMiddleware;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;
use Stringable;

#[MaxSteps(10)]
#[Temperature(1.0)]
#[Timeout(120)]
class FamilyAssistant implements Agent, Conversational, HasMiddleware, HasProviderOptions, HasTools
{
    use Promptable, RemembersConversations;

    public function __construct(public User $user) {}

    /**
     * Get the provider to use for this agent.
     */
    public function provider(): string
    {
        return config('ai.agent.provider', 'ollama');
    }

    /**
     * Get the model to use for this agent.
     */
    public function model(): string
    {
        return config('ai.agent.model', 'llama3.2');
    }

    /**
     * Get the instructions that the agent should follow.
     */
    public function instructions(): Stringable|string
    {
        $familyName = $this->user->family?->name ?? 'your family';
        $userName = $this->user->name;
        $currency = $this->user->family?->currency ?? '₦';
        $currentDate = now()->format('F j, Y');
        $currentYear = now()->year;
        $currentMonth = now()->month;

        return <<<INSTRUCTIONS
        You are a helpful AI assistant for the "{$familyName}" family contribution tracking group.
        You are speaking with {$userName}.
        Today's date is {$currentDate}. The current year is {$currentYear} and the current month is {$currentMonth}.

        Your capabilities:
        1. **Chat Assistant**: Answer general questions about the family group, contributions, and how the system works.
        2. **Financial Analyzer**: Analyze contribution payments, expenses, and provide financial insights for the family.
        3. **Report Summarizer**: Summarize monthly and annual contribution reports, highlight trends, and identify issues.

        Guidelines:
        - All monetary values are in {$currency} (Nigerian Naira).
        - Use the available tools to fetch real-time data before answering financial questions.
        - When querying data, always use the current year ({$currentYear}) unless the user specifically asks about a different time period.
        - Be concise but thorough. Use bullet points and tables when helpful.
        - If you don't have enough data, say so rather than guessing.
        - Never reveal sensitive information about other members unless the user is an admin.
        - Be friendly and professional.
        INSTRUCTIONS;
    }

    /**
     * Get the tools available to the agent.
     *
     * @return Tool[]
     */
    public function tools(): iterable
    {
        return [
            new GetContributionSummary($this->user),
            new GetExpenseSummary($this->user),
            new GetFundBalance($this->user),
            new GetMemberOverview($this->user),
        ];
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
}
