<?php

declare(strict_types=1);

use App\Models\Contribution;
use App\Models\Family;
use App\Models\FamilyMembership;
use App\Models\FinancialReversal;
use App\Models\Payment;
use App\Models\PaymentBatch;
use App\Models\User;
use Illuminate\Support\Collection;
use Inertia\Testing\AssertableInertia as Assert;

test('dashboard keeps distinct custom deadlines and legacy monthly deadlines for the same period', function (): void {
    $this->travelTo(now()->setDate(2030, 6, 15)->setTime(12, 0));
    $family = Family::factory()->create();
    $officer = User::factory()->financialSecretary()->create(['family_id' => $family->id]);
    $expectedStatuses = [];
    foreach ([['2030-06-14', 0, 'overdue'], ['2030-06-16', 0, 'unpaid'], ['2030-06-16', 2000, 'partial'], ['2030-06-14', 4000, 'paid'], [null, 0, 'unpaid']] as [$dueDate, $paid, $status]) {
        $member = User::factory()->member()->employed()->create(['family_id' => $family->id]);
        $contribution = Contribution::factory()->forUser($member)->currentMonth()->create();
        $contribution->forceFill(['due_date' => $dueDate])->saveQuietly();
        if ($paid > 0) {
            Payment::factory()->forContribution($contribution)->recordedBy($officer)->create(['amount' => $paid]);
        }
        $expectedStatuses[$member->id] = $status;
    }

    $this->actingAs($officer)->get(route('dashboard', ['current_family' => $family->slug]))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->where('member_statuses', fn (mixed $statuses): bool => $statuses instanceof Collection
                && $statuses->pluck('current_month_status', 'id')->all() === $expectedStatuses)
            ->where('summary.overdue_count', 1)
            ->where('summary.total_collected', 6000));
});

test('dashboard preserves effective totals and recent payment ordering for legacy contributions', function (int $day, string $status): void {
    $this->travelTo(now()->setDate(2030, 6, $day)->setTime(12, 0));
    $family = Family::factory()->create();
    $officer = User::factory()->financialSecretary()->create(['family_id' => $family->id]);
    $member = User::factory()->member()->employed()->create(['family_id' => $family->id]);
    $membership = $member->membershipForFamily($family);
    assert($membership instanceof FamilyMembership);
    $membership->update(['display_name' => 'Family display name']);
    $contribution = Contribution::factory()->forUser($member)->currentMonth()->employed()->create();
    $contribution->forceFill(['due_date' => null, 'category_name' => null])->saveQuietly();
    $paymentIds = [];
    foreach (range(1, 11) as $index) {
        $paymentIds[] = Payment::factory()->forContribution($contribution)->recordedBy($officer)->create([
            'amount' => 100,
            'paid_at' => now()->setDay(10)->setTime(23 - $index, 0),
        ])->id;
    }
    $batch = PaymentBatch::factory()->create(['family_id' => $family->id, 'recorded_by' => $officer->id, 'total_amount' => 900]);
    Payment::factory()->forContribution($contribution)->recordedBy($officer)->create(['payment_batch_id' => $batch->id, 'amount' => 900]);
    FinancialReversal::factory()->create([
        'family_id' => $family->id, 'reversible_type' => PaymentBatch::MORPH_TYPE,
        'reversible_id' => $batch->id, 'reversed_by' => $officer->id,
    ]);
    $archived = User::factory()->member()->employed()->create(['family_id' => $family->id]);
    $archivedContribution = Contribution::factory()->forUser($archived)->currentMonth()->employed()->create();
    Payment::factory()->forContribution($archivedContribution)->recordedBy($officer)->create(['amount' => 8000]);
    $archived->membershipForFamily($family)?->update(['archived_at' => now()]);
    $otherMember = User::factory()->member()->employed()->create();
    $otherContribution = Contribution::factory()->forUser($otherMember)->currentMonth()->employed()->create();
    Payment::factory()->forContribution($otherContribution)->create(['amount' => 10000]);
    $recentIds = array_slice(array_reverse($paymentIds), 0, 10);

    $this->actingAs($officer)->get(route('dashboard', ['current_family' => $family->slug]))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->where('summary.total_expected', 4000)
            ->where('summary.total_collected', 1100)
            ->where('summary.total_outstanding', 2900)
            ->has('member_statuses', 1)
            ->where('member_statuses.0.name', 'Family display name')
            ->where('member_statuses.0.category_label', $membership->categoryLabel())
            ->where('member_statuses.0.current_month_status', $status)
            ->has('recent_payments', 10)
            ->where('recent_payments', fn (mixed $payments): bool => $payments instanceof Collection && $payments->pluck('id')->all() === $recentIds)
            ->where('recent_payments.0.member_name', 'Family display name')
            ->where('summary.overdue_count', $status === 'overdue' ? 1 : 0));
})->with([
    'before legacy due date' => [15, 'partial'],
    'after legacy due date' => [29, 'overdue'],
]);
