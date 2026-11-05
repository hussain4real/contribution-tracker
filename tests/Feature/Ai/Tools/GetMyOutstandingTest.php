<?php

declare(strict_types=1);

use App\Actions\ReversePaymentBatch;
use App\Ai\Tools\GetMyOutstanding;
use App\Models\Contribution;
use App\Models\Family;
use App\Models\FamilyMembership;
use App\Models\Payment;
use App\Models\User;
use App\Services\PaymentAllocationService;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\Tools\Request;

it('answers personal arrears across years without netting another period overpayment or disclosing other members', function () {
    $this->travelTo(now()->setDate(2026, 11, 5));
    $family = Family::factory()->create(['member_financial_visibility' => 'personal']);
    $member = User::factory()->member()->employed()->create(['family_id' => $family->id]);
    $officer = User::factory()->admin()->create(['family_id' => $family->id]);
    $old = Contribution::factory()->forUser($member)->create(['year' => 2025, 'month' => 12, 'due_date' => '2025-12-28', 'expected_amount' => 4000]);
    $future = Contribution::factory()->forUser($member)->create(['year' => 2026, 'month' => 11, 'due_date' => '2026-11-28', 'expected_amount' => 4000]);
    Contribution::factory()->forUser($officer)->create(['expected_amount' => 9999]);
    $elsewhere = Family::factory()->create();
    Contribution::factory()->forUser($member)->create(['family_id' => $elsewhere->id, 'year' => 2024, 'month' => 1, 'expected_amount' => 8888]);
    $batch = app(PaymentAllocationService::class)->createBatch(member: $member, amount: 1000, paidAt: today(), recordedBy: $officer, family: $family);
    $overpaid = Contribution::factory()->forUser($member)->create(['year' => 2026, 'month' => 1, 'expected_amount' => 100]);
    Payment::factory()->forContribution($overpaid)->create(['amount' => 500]);
    $tool = new GetMyOutstanding($member);
    $result = decodeToolResult($tool->handle(new Request));
    expect($result['period'])->toBe('All years')->and($result['scope'])->toBe('personal')
        ->and($result['outstanding'])->toBe(7000)->and($result['overdue'])->toBe(3000)->and($result['not_yet_overdue'])->toBe(4000)
        ->and($result['unpaid_count'])->toBe(2)->and($result['entries_truncated'])->toBeFalse();
    if (! is_string($result['source_url'])) {
        throw new RuntimeException('Missing source URL');
    }
    $this->actingAs($member)->get($result['source_url'])->assertOk();
    foreach (resultArray($result, 'contributions') as $entry) {
        if (! is_array($entry) || ! is_string($entry['source_url'] ?? null)) {
            throw new RuntimeException('Expected a contribution source link.');
        }
        $this->get($entry['source_url'])->assertOk();
    }
    app(ReversePaymentBatch::class)->handle($batch, $officer, 'Reversal restores arrears');
    $after = decodeToolResult($tool->handle(new Request));
    expect($after['outstanding'])->toBe(8000)->and($after['overdue'])->toBe(4000);
    $filtered = decodeToolResult($tool->handle(new Request(['year' => 2025, 'month' => 12])));
    expect($filtered['period'])->toBe('2025-12')->and($filtered['outstanding'])->toBe(4000);
});

it('rechecks membership and supports empty personal records', function () {
    $family = Family::factory()->create();
    $member = User::factory()->member()->create(['family_id' => $family->id]);
    $member->load('familyMemberships');
    $tool = new GetMyOutstanding($member);
    $empty = decodeToolResult($tool->handle(new Request));
    expect($empty['outstanding'])->toBe(0)->and($empty['contributions'])->toBe([]);
    FamilyMembership::where('family_id', $family->id)->where('user_id', $member->id)->update(['archived_at' => now()]);
    expect(decodeToolResult($tool->handle(new Request)))->toHaveKey('error');
});

it('rejects invalid and ambiguous reporting periods', function () {
    $member = User::factory()->member()->create();
    (new GetMyOutstanding($member))->handle(new Request(['month' => 2]));
})->throws(ValidationException::class);
