<?php

declare(strict_types=1);

use App\Ai\Tools\GenerateContributions;
use App\Ai\Tools\GetContributionSummary;
use App\Ai\Tools\GetExpenseSummary;
use App\Ai\Tools\GetMemberOverview;
use App\Ai\Tools\RecordExpense;
use App\Ai\Tools\RecordFundAdjustment;
use App\Ai\Tools\RecordPayment;
use App\Ai\Tools\SendInvitation;
use App\Models\User;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

test('family scoped AI tools require a family context', function (string $toolClass, array $payload) {
    $user = User::factory()->admin()->create(['family_id' => null]);

    $tool = match ($toolClass) {
        GenerateContributions::class => new GenerateContributions($user),
        GetContributionSummary::class => new GetContributionSummary($user),
        GetExpenseSummary::class => new GetExpenseSummary($user),
        GetMemberOverview::class => new GetMemberOverview($user),
        RecordExpense::class => new RecordExpense($user),
        RecordFundAdjustment::class => new RecordFundAdjustment($user),
        RecordPayment::class => new RecordPayment($user),
        SendInvitation::class => new SendInvitation($user),
        default => throw new InvalidArgumentException('Unsupported AI tool class.'),
    };

    expect($tool)->toBeInstanceOf(Tool::class);

    $result = decodeToolResult($tool->handle(new Request($payload)));

    expect($result)->toHaveKey('error', 'User is not associated with a family.');
})->with([
    'generate contributions' => [GenerateContributions::class, []],
    'contribution summary' => [GetContributionSummary::class, []],
    'expense summary' => [GetExpenseSummary::class, []],
    'member overview' => [GetMemberOverview::class, []],
    'record expense' => [RecordExpense::class, [
        'amount' => 5000,
        'description' => 'Generator fuel',
    ]],
    'record fund adjustment' => [RecordFundAdjustment::class, [
        'amount' => 5000,
        'description' => 'Donation',
    ]],
    'record payment' => [RecordPayment::class, []],
    'send invitation' => [SendInvitation::class, []],
]);
