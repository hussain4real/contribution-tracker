<?php

namespace App\Ai\Agents;

use App\Ai\Middleware\LogPrompts;
use App\Ai\Tools\GenerateContributions;
use App\Ai\Tools\GetContributionSummary;
use App\Ai\Tools\GetExpenseSummary;
use App\Ai\Tools\GetFundBalance;
use App\Ai\Tools\GetMemberOverview;
use App\Ai\Tools\RecordExpense;
use App\Ai\Tools\RecordFundAdjustment;
use App\Ai\Tools\RecordPayment;
use App\Ai\Tools\SendInvitation;
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
        $roleCapabilities = $this->getRoleCapabilities();

        return <<<INSTRUCTIONS
        You are a helpful AI assistant for the "{$familyName}" family contribution tracking group.
        You are speaking with {$userName} (role: {$this->user->role->value}).
        Today's date is {$currentDate}. The current year is {$currentYear} and the current month is {$currentMonth}.

        Your capabilities:
        1. **Chat Assistant**: Answer general questions about the family group, contributions, and how the system works.
        2. **Financial Analyzer**: Analyze contribution payments, expenses, and provide financial insights for the family.
        3. **Report Summarizer**: Summarize monthly and annual contribution reports, highlight trends, and identify issues.
        4. **Agentic Actions**: Perform write operations on behalf of the user based on their role permissions.

        {$userName}'s permissions based on their role:
        {$roleCapabilities}

        CRITICAL - Confirm-First Rule for Write Actions:
        - When using any write tool (recording expenses, payments, fund adjustments, generating contributions, or sending invitations), you MUST call the tool first WITHOUT setting confirmed=true.
        - Present the preview/summary to the user and ask for their explicit confirmation.
        - Only after the user confirms (says yes, confirm, go ahead, etc.), call the tool again WITH confirmed=true to execute the action.
        - NEVER set confirmed=true on the first call. Always preview first.

        Guidelines:
        - All monetary values are in {$currency} (Nigerian Naira).
        - Use the available tools to fetch real-time data before answering financial questions.
        - When querying data, always use the current year ({$currentYear}) unless the user specifically asks about a different time period.
        - Be concise but thorough. Use bullet points and tables when helpful.
        - If you don't have enough data, say so rather than guessing.
        - Never reveal sensitive information about other members unless the user is an admin.
        - If the user asks to perform an action they don't have permission for, explain what role is required.
        - STRICT SCOPE: You must refuse to answer any questions or perform any tasks that are not related to family funds, contributions, expenses, or the tracking system. If asked something off-topic (like general knowledge), politely decline and remind the user of your specific purpose.
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
        $tools = [
            new GetContributionSummary($this->user),
            new GetExpenseSummary($this->user),
            new GetFundBalance($this->user),
            new GetMemberOverview($this->user),
        ];

        if ($this->user->canRecordPayments()) {
            $tools[] = new RecordExpense($this->user);
            $tools[] = new RecordPayment($this->user);
            $tools[] = new RecordFundAdjustment($this->user);
        }

        if ($this->user->isAdmin()) {
            $tools[] = new GenerateContributions($this->user);
            $tools[] = new SendInvitation($this->user);
        }

        return $tools;
    }

    /**
     * Get the role-specific capabilities description for the instructions.
     */
    private function getRoleCapabilities(): string
    {
        $capabilities = ['- Can view contribution summaries, expense reports, fund balance, and member overviews'];

        if ($this->user->canRecordPayments()) {
            $capabilities[] = '- Can record expenses for the family';
            $capabilities[] = '- Can record payments for family members';
            $capabilities[] = '- Can record fund adjustments (donations, corrections, interest)';
        }

        if ($this->user->isAdmin()) {
            $capabilities[] = '- Can generate monthly contribution records for all members';
            $capabilities[] = '- Can send invitations to new family members';
        }

        if (! $this->user->canRecordPayments()) {
            $capabilities[] = '- Cannot record expenses, payments, or fund adjustments (requires admin or financial secretary role)';
        }

        if (! $this->user->isAdmin()) {
            $capabilities[] = '- Cannot generate contributions or send invitations (requires admin role)';
        }

        return implode("\n        ", $capabilities);
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
