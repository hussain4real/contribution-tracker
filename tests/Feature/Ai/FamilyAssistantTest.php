<?php

use App\Ai\Agents\ContributionAnalysisAgent;
use App\Ai\Agents\ContributionGenerationAgent;
use App\Ai\Agents\ExpenseAnalysisAgent;
use App\Ai\Agents\ExpenseRecordingAgent;
use App\Ai\Agents\FamilyAssistant;
use App\Ai\Agents\FundAdjustmentRecordingAgent;
use App\Ai\Agents\FundBalanceAgent;
use App\Ai\Agents\InvitationAgent;
use App\Ai\Agents\MemberStatusAgent;
use App\Ai\Agents\PaymentRecordingAgent;
use App\Ai\Middleware\LogPrompts;
use App\Models\Family;
use App\Models\User;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Prompts\AgentPrompt;

it('uses configured provider and model values', function () {
    config([
        'ai.agent.provider' => 'ollama',
        'ai.agent.model' => 'llama3.2',
    ]);

    $agent = new FamilyAssistant(User::factory()->create());

    expect($agent->provider())->toBe('ollama')
        ->and($agent->model())->toBe('llama3.2');
});

it('builds instructions for members without a family workspace', function () {
    $user = User::factory()->member()->create([
        'name' => 'Amina',
        'family_id' => null,
    ]);

    $instructions = (new FamilyAssistant($user))->instructions();

    expect($instructions)
        ->toContain('the "your family" family contribution tracking group')
        ->toContain('You are speaking with Amina (role: member)')
        ->toContain('Coordinator')
        ->toContain('Each sub-agent runs in isolation')
        ->toContain('Cannot record expenses, payments, or fund adjustments')
        ->toContain('Cannot generate contributions or send invitations')
        ->toContain('All monetary values are in ₦');
});

it('builds instructions with elevated role capabilities and family currency', function () {
    $family = Family::factory()->create([
        'name' => 'Smith Family',
        'currency' => 'NGN',
    ]);
    $admin = User::factory()->admin()->create([
        'name' => 'Ada',
        'family_id' => $family->id,
    ]);

    $instructions = (new FamilyAssistant($admin))->instructions();

    expect($instructions)
        ->toContain('the "Smith Family" family contribution tracking group')
        ->toContain('You are speaking with Ada (role: admin)')
        ->toContain('contribution_analysis, expense_analysis, fund_balance, and member_status')
        ->toContain('delegate the same exact action details again WITH confirmed=true')
        ->toContain('Can record expenses for the family')
        ->toContain('Can generate monthly contribution records for all members')
        ->toContain('All monetary values are in NGN')
        ->not->toContain('Cannot record expenses');
});

it('exposes tools based on the authenticated user role', function (string $state, array $expectedTools) {
    $user = User::factory()->{$state}()->create();

    $tools = collect((new FamilyAssistant($user))->tools())
        ->map(fn (object $tool): string => $tool::class)
        ->all();

    expect($tools)->toBe($expectedTools);
})->with([
    'member' => [
        'member',
        [
            ContributionAnalysisAgent::class,
            ExpenseAnalysisAgent::class,
            FundBalanceAgent::class,
            MemberStatusAgent::class,
        ],
    ],
    'financial secretary' => [
        'financialSecretary',
        [
            ContributionAnalysisAgent::class,
            ExpenseAnalysisAgent::class,
            FundBalanceAgent::class,
            MemberStatusAgent::class,
            PaymentRecordingAgent::class,
            ExpenseRecordingAgent::class,
            FundAdjustmentRecordingAgent::class,
        ],
    ],
    'admin' => [
        'admin',
        [
            ContributionAnalysisAgent::class,
            ExpenseAnalysisAgent::class,
            FundBalanceAgent::class,
            MemberStatusAgent::class,
            PaymentRecordingAgent::class,
            ExpenseRecordingAgent::class,
            FundAdjustmentRecordingAgent::class,
            ContributionGenerationAgent::class,
            InvitationAgent::class,
        ],
    ],
]);

it('registers prompt logging middleware', function () {
    $agent = new FamilyAssistant(User::factory()->create());

    expect($agent->middleware())
        ->toHaveCount(1)
        ->and($agent->middleware()[0])->toBeInstanceOf(LogPrompts::class);
});

it('returns ollama provider options only for ollama', function () {
    $agent = new FamilyAssistant(User::factory()->create());

    expect($agent->providerOptions(Lab::Ollama))->toBe([
        'top_p' => 0.95,
        'top_k' => 64,
    ])
        ->and($agent->providerOptions(Lab::OpenAI))->toBe([])
        ->and($agent->providerOptions('custom-provider'))->toBe([]);
});

it('can be faked with the Laravel AI testing API', function () {
    $user = User::factory()->create();

    FamilyAssistant::fake(['Here is the family balance.'])->preventStrayPrompts();

    $response = (new FamilyAssistant($user))->prompt('What is the current family balance?');

    expect($response->text)->toBe('Here is the family balance.');

    FamilyAssistant::assertPrompted(
        fn (AgentPrompt $prompt): bool => $prompt->contains('current family balance')
            && $prompt->agent instanceof FamilyAssistant,
    );
});
