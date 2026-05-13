<?php

use App\Ai\Agents\ContributionAnalysisAgent;
use App\Ai\Agents\ContributionGenerationAgent;
use App\Ai\Agents\ExpenseAnalysisAgent;
use App\Ai\Agents\ExpenseRecordingAgent;
use App\Ai\Agents\FundAdjustmentRecordingAgent;
use App\Ai\Agents\FundBalanceAgent;
use App\Ai\Agents\InvitationAgent;
use App\Ai\Agents\MemberStatusAgent;
use App\Ai\Agents\PaymentRecordingAgent;
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
use App\Models\Family;
use App\Models\User;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Tools\AgentTool;
use Laravel\Ai\Tools\Request as ToolRequest;

it('uses shared configuration for every family sub-agent', function (string $agentClass) {
    config([
        'ai.agent.provider' => 'ollama',
        'ai.agent.model' => 'llama3.2',
    ]);

    $agent = new $agentClass(User::factory()->admin()->create());

    expect($agent->provider())->toBe('ollama')
        ->and($agent->model())->toBe('llama3.2')
        ->and($agent->maxSteps())->toBe(6)
        ->and($agent->temperature())->toBe(1.0)
        ->and($agent->timeout())->toBe(120)
        ->and($agent->middleware())->toHaveCount(1)
        ->and($agent->middleware()[0])->toBeInstanceOf(LogPrompts::class)
        ->and($agent->providerOptions(Lab::Ollama))->toBe([
            'top_p' => 0.95,
            'top_k' => 64,
        ])
        ->and($agent->providerOptions(Lab::OpenAI))->toBe([])
        ->and($agent->providerOptions('custom-provider'))->toBe([]);
})->with('family sub agents');

it('defines tool metadata, instructions, and underlying tools', function (
    string $agentClass,
    string $name,
    string $descriptionFragment,
    array $instructionFragments,
    array $expectedTools,
) {
    $family = Family::factory()->create([
        'name' => 'Smith Family',
        'currency' => 'NGN',
    ]);
    $agent = new $agentClass(User::factory()->admin()->create([
        'name' => 'Ada',
        'family_id' => $family->id,
    ]));

    $instructions = $agent->instructions();
    $tools = collect($agent->tools())
        ->map(fn (object $tool): string => $tool::class)
        ->all();

    expect($agent->name())->toBe($name)
        ->and($agent->description())->toContain($descriptionFragment)
        ->and($instructions)->toContain('Family workspace: Smith Family')
        ->and($instructions)->toContain('User: Ada (role: admin)')
        ->and($instructions)->toContain('Currency: NGN')
        ->and($tools)->toBe($expectedTools);

    foreach ($instructionFragments as $fragment) {
        expect($instructions)->toContain($fragment);
    }
})->with([
    'contribution analysis' => [
        ContributionAnalysisAgent::class,
        'contribution_analysis',
        'contribution expectations',
        ['contribution analysis specialist', 'collection rates', 'data gaps'],
        [GetContributionSummary::class],
    ],
    'expense analysis' => [
        ExpenseAnalysisAgent::class,
        'expense_analysis',
        'family expenses',
        ['expense analysis specialist', 'date ranges', 'no matching expenses'],
        [GetExpenseSummary::class],
    ],
    'fund balance' => [
        FundBalanceAgent::class,
        'fund_balance',
        'current family fund balance',
        ['fund balance specialist', 'component breakdown', 'payments plus fund adjustments minus expenses'],
        [GetFundBalance::class],
    ],
    'member status' => [
        MemberStatusAgent::class,
        'member_status',
        'active members',
        ['member status specialist', 'current-month payment status', 'sensitive member detail'],
        [GetMemberOverview::class],
    ],
    'payment recording' => [
        PaymentRecordingAgent::class,
        'payment_recording',
        'family member payments',
        ['payment recording specialist', 'Confirm-first rule', 'member name and amount'],
        [RecordPayment::class],
    ],
    'expense recording' => [
        ExpenseRecordingAgent::class,
        'expense_recording',
        'family expenses',
        ['expense recording specialist', 'Confirm-first rule', 'amount and description'],
        [RecordExpense::class],
    ],
    'fund adjustment recording' => [
        FundAdjustmentRecordingAgent::class,
        'fund_adjustment_recording',
        'fund adjustments',
        ['fund adjustment recording specialist', 'Confirm-first rule', 'donations, corrections, interest earned'],
        [RecordFundAdjustment::class],
    ],
    'contribution generation' => [
        ContributionGenerationAgent::class,
        'contribution_generation',
        'monthly contribution records',
        ['contribution generation specialist', 'Confirm-first rule', 'creates expected contribution entries'],
        [GenerateContributions::class],
    ],
    'invitation management' => [
        InvitationAgent::class,
        'invitation_management',
        'family invitations',
        ['invitation management specialist', 'Confirm-first rule', 'admin, financial_secretary, or member'],
        [SendInvitation::class],
    ],
]);

it('can delegate an insight request through the Laravel sub-agent tool wrapper', function () {
    $user = User::factory()->admin()->create();
    $agent = new FundBalanceAgent($user);
    $tool = new AgentTool($agent);

    FundBalanceAgent::fake(['The current fund balance is NGN 10,000.'])->preventStrayPrompts();

    $result = $tool->handle(new ToolRequest([
        'task' => 'Explain the current family balance for this month.',
    ]));

    expect($tool->name())->toBe('fund_balance')
        ->and($tool->description())->toContain('current family fund balance')
        ->and($result)->toBe('The current fund balance is NGN 10,000.');

    FundBalanceAgent::assertPrompted(
        fn (AgentPrompt $prompt): bool => $prompt->contains('current family balance')
            && $prompt->agent instanceof FundBalanceAgent,
    );
});

it('can delegate a confirm-first operation request through the Laravel sub-agent tool wrapper', function () {
    $user = User::factory()->financialSecretary()->create();
    $agent = new PaymentRecordingAgent($user);
    $tool = new AgentTool($agent);

    PaymentRecordingAgent::fake(['Preview ready: record payment after confirmation.'])->preventStrayPrompts();

    $result = $tool->handle(new ToolRequest([
        'task' => 'Preview recording a payment of 5000 for Ada without confirmed=true.',
    ]));

    expect($tool->name())->toBe('payment_recording')
        ->and($tool->description())->toContain('confirm-first workflow')
        ->and($result)->toBe('Preview ready: record payment after confirmation.');

    PaymentRecordingAgent::assertPrompted(
        fn (AgentPrompt $prompt): bool => $prompt->contains('without confirmed=true')
            && $prompt->agent instanceof PaymentRecordingAgent,
    );
});

dataset('family sub agents', [
    ContributionAnalysisAgent::class,
    ExpenseAnalysisAgent::class,
    FundBalanceAgent::class,
    MemberStatusAgent::class,
    PaymentRecordingAgent::class,
    ExpenseRecordingAgent::class,
    FundAdjustmentRecordingAgent::class,
    ContributionGenerationAgent::class,
    InvitationAgent::class,
]);
