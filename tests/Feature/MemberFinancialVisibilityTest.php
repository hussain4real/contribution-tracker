<?php

declare(strict_types=1);

use App\Ai\Tools\GetContributionSummary;
use App\Ai\Tools\GetExpenseSummary;
use App\Ai\Tools\GetFundBalance;
use App\Ai\Tools\GetMemberOverview;
use App\Models\Contribution;
use App\Models\Expense;
use App\Models\Family;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Ai\Tools\Request as ToolRequest;

beforeEach(function () {
    $this->family = Family::factory()->create();
    $this->admin = User::factory()->admin()->create(['family_id' => $this->family->id]);
    $this->member = User::factory()->member()->employed()->create(['family_id' => $this->family->id]);
    Expense::factory()->recordedBy($this->admin)->create(['amount' => 500, 'description' => 'Family transport']);
});

it('shows only personal records when the family selects personal visibility', function () {
    $this->family->update(['member_financial_visibility' => 'personal']);
    $this->actingAs($this->member)->get(route('dashboard'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->has('personal')->missing('family_aggregate')->missing('fund_balance')
        ->where('auth.can.view_financial_transactions', false));
    $this->get(route('expenses.index'))->assertForbidden();
    $this->get(route('fund-adjustments.index'))->assertForbidden();
    $this->get(route('family.activity'))->assertForbidden();
    expect(json_decode((new GetFundBalance($this->member))->handle(new ToolRequest([])), true))->toHaveKey('error');
    expect(json_decode((new GetExpenseSummary($this->member))->handle(new ToolRequest([])), true))->toHaveKey('error');
});

it('shows totals without exposing transactions by default', function () {
    $this->actingAs($this->member)->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
        ->has('family_aggregate')->where('fund_balance', -500)->missing('financial_overview'));
    $this->get(route('expenses.index'))->assertForbidden();
    $this->get(route('fund-adjustments.index'))->assertForbidden();
    $this->get(route('family.activity'))->assertForbidden();
    $summary = json_decode((new GetFundBalance($this->member))->handle(new ToolRequest(['include_breakdown' => true])), true);
    expect($summary)->toHaveKey('fund_balance')->not->toHaveKey('recent_adjustments')->not->toHaveKey('breakdown');
});

it('lets members see transaction history only after the family opts in and honors later revocation', function () {
    $this->family->update(['member_financial_visibility' => 'transactions']);
    $this->actingAs($this->member)->get(route('expenses.index'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('expenses.data', 1)->where('expenses.data.0.can_upload', false));
    $this->get(route('family.activity'))->assertOk()->assertInertia(fn (Assert $page) => $page->has('entries.data', 1));
    $this->family->update(['member_financial_visibility' => 'personal']);
    $this->get(route('family.activity'))->assertForbidden();
    $this->get(route('expenses.index'))->assertForbidden();
    $this->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page->missing('fund_balance'));
});

it('preserves officer access to totals and activity under every member visibility setting', function (string $visibility) {
    $this->family->update(['member_financial_visibility' => $visibility]);
    $this->actingAs($this->admin)->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page->has('financial_overview'));
    $this->get(route('expenses.index'))->assertOk();
    $this->get(route('family.activity'))->assertOk();
})->with(['personal', 'summary', 'transactions']);

it('limits AI contribution and member breakdowns to the member and honors newly restricted totals', function () {
    Contribution::factory()->forUser($this->member)->currentMonth()->create(['expected_amount' => 3000]);
    Contribution::factory()->forUser($this->admin)->currentMonth()->create(['expected_amount' => 9000]);
    $this->member->load('family');
    $this->family->update(['member_financial_visibility' => 'personal']);
    $result = decodeToolResult((new GetContributionSummary($this->member))->handle(new ToolRequest([])));
    expect($result)->toHaveKey('total_expected', 3000);
    expect(resultArray($result, 'members'))->toHaveCount(1);
    $members = decodeToolResult((new GetMemberOverview($this->member))->handle(new ToolRequest([])));
    expect($members)->toHaveKey('total_members', 1);
    expect(decodeToolResult((new GetFundBalance($this->member))->handle(new ToolRequest([]))))->toHaveKey('error');
    expect(decodeToolResult((new GetExpenseSummary($this->member))->handle(new ToolRequest([]))))->toHaveKey('error');
});
