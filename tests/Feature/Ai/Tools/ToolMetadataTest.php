<?php

declare(strict_types=1);

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
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Laravel\Ai\Contracts\Tool;

/**
 * @param  class-string<Tool>  $toolClass
 * @param  list<string>  $expectedSchemaKeys
 */
it('exposes descriptions and json schemas for all ai tools', function (string $toolClass, array $expectedSchemaKeys) {
    $toolClass = classStringOf($toolClass, Tool::class);
    $tool = new $toolClass(User::factory()->admin()->create());
    $schema = $tool->schema(new JsonSchemaTypeFactory);

    expect((string) $tool->description())->not->toBeEmpty()
        ->and(array_keys($schema))->toBe($expectedSchemaKeys);
})->with([
    'generate contributions' => [GenerateContributions::class, ['year', 'month', 'confirmed']],
    'get contribution summary' => [GetContributionSummary::class, ['year', 'month']],
    'get expense summary' => [GetExpenseSummary::class, ['start_date', 'end_date']],
    'get fund balance' => [GetFundBalance::class, ['include_breakdown']],
    'get member overview' => [GetMemberOverview::class, []],
    'record expense' => [RecordExpense::class, ['amount', 'description', 'spent_at', 'confirmed']],
    'record fund adjustment' => [RecordFundAdjustment::class, ['amount', 'description', 'recorded_at', 'confirmed']],
    'record payment' => [RecordPayment::class, ['member_name', 'amount', 'paid_at', 'notes', 'target_year', 'target_month', 'confirmed']],
    'send invitation' => [SendInvitation::class, ['delivery_method', 'email', 'whatsapp_phone', 'role', 'confirmed']],
]);
