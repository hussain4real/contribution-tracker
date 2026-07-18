<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Actions\CorrectExpense;
use App\Actions\CorrectFundAdjustment;
use App\Actions\CorrectPaymentBatch;
use App\Actions\ReverseExpense;
use App\Actions\ReverseFundAdjustment;
use App\Actions\ReversePaymentBatch;
use App\Enums\PaymentMethod;
use App\Enums\PaymentSource;
use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Models\Contribution;
use App\Models\Expense;
use App\Models\Family;
use App\Models\FamilyMembership;
use App\Models\FundAdjustment;
use App\Models\PaymentBatch;
use App\Models\PaystackTransaction;
use App\Models\User;
use App\Services\PaymentAllocationService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use LogicException;

class DemoLedgerSeeder extends Seeder
{
    public function __construct(
        private readonly PaymentAllocationService $allocationService,
        private readonly CorrectPaymentBatch $correctPaymentBatch,
        private readonly ReversePaymentBatch $reversePaymentBatch,
        private readonly CorrectExpense $correctExpense,
        private readonly ReverseExpense $reverseExpense,
        private readonly CorrectFundAdjustment $correctFundAdjustment,
        private readonly ReverseFundAdjustment $reverseFundAdjustment,
    ) {}

    public function run(): void
    {
        $family = Family::query()->where('slug', 'demo-family')->firstOrFail();
        $finance = User::query()->where('email', 'finance@family.test')->firstOrFail();
        $member = User::query()->where('email', 'member@family.test')->firstOrFail();
        $partial = User::query()->where('email', 'partial@family.test')->firstOrFail();
        $overdue = User::query()->where('email', 'overdue@family.test')->firstOrFail();
        $student = User::query()->where('email', 'student@family.test')->firstOrFail();
        $periods = array_map(
            fn (int $monthsAgo): CarbonImmutable => CarbonImmutable::now()->startOfMonth()->subMonths($monthsAgo),
            range(5, 0),
        );

        foreach ([$finance, $member, $partial, $overdue, $student] as $payingMember) {
            foreach ($periods as $period) {
                $this->contribution($family, $payingMember, $period);
            }
        }

        $this->payMonths($family, $finance, $finance, $periods, 'finance');
        $this->splitPayment($family, $member, $finance, array_slice($periods, 0, 2));
        $memberBatches = $this->payMonths($family, $member, $finance, array_slice($periods, 2), 'member');
        $partialBatches = $this->payMonths($family, $partial, $finance, array_slice($periods, 0, 5), 'partial');
        $this->partialPayment($family, $partial, $finance, CarbonImmutable::now()->startOfMonth());
        $this->payMonths($family, $overdue, $finance, array_slice($periods, 0, 4), 'overdue');
        $this->payMonths($family, $student, $finance, array_slice($periods, 0, 5), 'student');

        $originalBatch = $partialBatches[0];
        if (! $originalBatch->reversal()->exists()) {
            $this->correctPaymentBatch->handle(
                original: $originalBatch,
                actor: $finance,
                reason: 'Demo correction: payment method was recorded incorrectly.',
                amount: $originalBatch->total_amount,
                paidAt: $originalBatch->paid_at,
                method: PaymentMethod::BankTransfer,
                reference: 'DEMO-CORRECTED-RECEIPT',
                notes: 'Corrected demo receipt.',
                idempotencyKey: 'demo:payment:corrected-partial',
            );
        }

        $allocatedBatch = $memberBatches[count($memberBatches) - 1];
        $this->allocatedPaystackTransaction($family, $member, $allocatedBatch);
        $this->reversedPaystackTransaction($family, $student, $finance, CarbonImmutable::now()->startOfMonth());
        $this->otherPaystackStates($family, $partial, $overdue);
        $this->moneyOutAndAdjustments($family, $finance);
    }

    private function contribution(Family $family, User $member, CarbonImmutable $period): Contribution
    {
        $membership = $this->membership($family, $member);
        $snapshot = $membership->contributionCategorySnapshot($period->year, $period->month);
        $isPreviousMonth = $period->isSameMonth(CarbonImmutable::now()->subMonth());
        $isCurrentMonth = $period->isSameMonth(CarbonImmutable::now());

        return Contribution::query()->updateOrCreate(
            [
                'family_id' => $family->id,
                'user_id' => $member->id,
                'year' => $period->year,
                'month' => $period->month,
            ],
            [
                ...$snapshot,
                'expected_amount' => $snapshot['category_amount'],
                'due_date' => Contribution::dueDateForMonth($period->year, $period->month, $family->due_day),
                'reminder_sent_at' => $isPreviousMonth ? $period->setDay(25) : null,
                'follow_up_sent_at' => $isPreviousMonth && ! $isCurrentMonth ? $period->setDay(28) : null,
            ],
        );
    }

    /**
     * @param  list<CarbonImmutable>  $periods
     * @return non-empty-list<PaymentBatch>
     */
    private function payMonths(
        Family $family,
        User $member,
        User $recorder,
        array $periods,
        string $keyPrefix,
    ): array {
        $batches = [];

        foreach ($periods as $period) {
            $contribution = $this->contributionFor($family, $member, $period);
            $isCurrentMonth = $period->isSameMonth(CarbonImmutable::now());
            $method = $isCurrentMonth && $keyPrefix === 'member'
                ? PaymentMethod::Paystack
                : ($period->month % 2 === 0 ? PaymentMethod::BankTransfer : PaymentMethod::Cash);
            $source = match (true) {
                $method === PaymentMethod::Paystack => PaymentSource::Paystack,
                $keyPrefix === 'finance' && $period->isSameMonth(CarbonImmutable::now()->subMonths(5)) => PaymentSource::Backfill,
                default => PaymentSource::Manual,
            };
            $reference = $method === PaymentMethod::Cash ? null : 'DEMO-'.mb_strtoupper($keyPrefix).'-'.$period->format('Ym');

            $batches[] = $this->allocationService->createBatch(
                member: $member,
                amount: $contribution->expected_amount,
                paidAt: $period->setDay(12),
                recordedBy: $method === PaymentMethod::Paystack ? $member : $recorder,
                notes: 'Demo seeded payment.',
                targetYear: $period->year,
                targetMonth: $period->month,
                family: $family,
                method: $method,
                source: $source,
                reference: $reference,
                idempotencyKey: "demo:payment:{$keyPrefix}:{$period->format('Ym')}",
            );
        }

        throw_if($batches === [], LogicException::class, 'At least one contribution period is required.');

        return $batches;
    }

    /** @param list<CarbonImmutable> $periods */
    private function splitPayment(Family $family, User $member, User $recorder, array $periods): PaymentBatch
    {
        $amount = collect($periods)
            ->sum(fn (CarbonImmutable $period): int => $this->contributionFor($family, $member, $period)->expected_amount);
        $firstPeriod = $periods[0];

        return $this->allocationService->createBatch(
            member: $member,
            amount: $amount,
            paidAt: $firstPeriod->setDay(10),
            recordedBy: $recorder,
            notes: 'Demo payment covering two contribution periods.',
            targetYear: $firstPeriod->year,
            targetMonth: $firstPeriod->month,
            family: $family,
            method: PaymentMethod::BankTransfer,
            source: PaymentSource::Manual,
            reference: 'DEMO-SPLIT-'.$firstPeriod->format('Ym'),
            idempotencyKey: 'demo:payment:member:split-two-months',
        );
    }

    private function partialPayment(Family $family, User $member, User $recorder, CarbonImmutable $period): PaymentBatch
    {
        $contribution = $this->contributionFor($family, $member, $period);

        return $this->allocationService->createBatch(
            member: $member,
            amount: (int) floor($contribution->expected_amount / 2),
            paidAt: $period->setDay(8),
            recordedBy: $recorder,
            notes: 'Demo partial contribution payment.',
            targetYear: $period->year,
            targetMonth: $period->month,
            family: $family,
            method: PaymentMethod::Other,
            source: PaymentSource::Manual,
            idempotencyKey: 'demo:payment:partial:current',
        );
    }

    private function allocatedPaystackTransaction(Family $family, User $member, PaymentBatch $batch): void
    {
        $transaction = PaystackTransaction::query()->firstOrCreate(
            ['reference' => 'DEMO-PAYSTACK-ALLOCATED'],
            [
                'user_id' => $member->id,
                'family_id' => $family->id,
                'type' => TransactionType::Contribution,
                'amount' => $batch->total_amount,
                'gross_amount_kobo' => $batch->total_amount * 100,
                'estimated_fee_kobo' => 0,
                'fee_policy' => 'payer_pays',
                'status' => TransactionStatus::Initiated,
                'metadata' => ['demo' => true],
            ],
        );

        if ($transaction->status !== TransactionStatus::Allocated) {
            $transaction->forceFill([
                'status' => TransactionStatus::Allocated,
                'payment_batch_id' => $batch->id,
                'verified_at' => now()->subMinute(),
                'allocated_at' => now(),
                'settled_amount_kobo' => $batch->total_amount * 100,
                'paystack_response' => ['status' => 'success', 'reference' => $transaction->reference],
            ])->save();
        }
    }

    private function reversedPaystackTransaction(
        Family $family,
        User $member,
        User $recorder,
        CarbonImmutable $period,
    ): void {
        $contribution = $this->contributionFor($family, $member, $period);
        $batch = $this->allocationService->createBatch(
            member: $member,
            amount: $contribution->expected_amount,
            paidAt: $period->setDay(9),
            recordedBy: $member,
            notes: 'Demo Paystack payment that was later reversed.',
            targetYear: $period->year,
            targetMonth: $period->month,
            family: $family,
            method: PaymentMethod::Paystack,
            source: PaymentSource::Paystack,
            reference: 'DEMO-PAYSTACK-REVERSED',
            idempotencyKey: 'demo:payment:student:reversed',
        );
        $transaction = PaystackTransaction::query()->firstOrCreate(
            ['reference' => 'DEMO-PAYSTACK-REVERSED'],
            [
                'user_id' => $member->id,
                'family_id' => $family->id,
                'type' => TransactionType::Contribution,
                'amount' => $batch->total_amount,
                'gross_amount_kobo' => $batch->total_amount * 100,
                'fee_policy' => 'merchant_pays',
                'status' => TransactionStatus::Initiated,
                'metadata' => ['demo' => true],
            ],
        );

        if ($transaction->payment_batch_id === null) {
            $transaction->forceFill([
                'status' => TransactionStatus::Allocated,
                'payment_batch_id' => $batch->id,
                'verified_at' => now()->subMinute(),
                'allocated_at' => now(),
            ])->save();
        }

        $this->reversePaymentBatch->handle($batch, $recorder, 'Demo Paystack refund and receipt reversal.');
    }

    private function otherPaystackStates(Family $family, User $partial, User $overdue): void
    {
        $definitions = [
            [
                'reference' => 'DEMO-PAYSTACK-INITIATED',
                'user' => $partial,
                'type' => TransactionType::Contribution,
                'status' => TransactionStatus::Initiated,
                'amount' => 4000,
            ],
            [
                'reference' => 'DEMO-PAYSTACK-VERIFIED',
                'user' => $partial,
                'type' => TransactionType::Contribution,
                'status' => TransactionStatus::Verified,
                'amount' => 4000,
            ],
            [
                'reference' => 'DEMO-PAYSTACK-FAILED',
                'user' => $overdue,
                'type' => TransactionType::Contribution,
                'status' => TransactionStatus::Failed,
                'amount' => 2000,
            ],
            [
                'reference' => 'DEMO-PAYSTACK-PENDING',
                'user' => $overdue,
                'type' => TransactionType::Contribution,
                'status' => TransactionStatus::Pending,
                'amount' => 2000,
            ],
            [
                'reference' => 'DEMO-PAYSTACK-ABANDONED',
                'user' => $overdue,
                'type' => TransactionType::Contribution,
                'status' => TransactionStatus::Abandoned,
                'amount' => 2000,
            ],
            [
                'reference' => 'DEMO-PAYSTACK-SUBSCRIPTION',
                'user' => $partial,
                'type' => TransactionType::Subscription,
                'status' => TransactionStatus::Success,
                'amount' => 7500,
            ],
            [
                'reference' => 'DEMO-PAYSTACK-ONE-TIME',
                'user' => $partial,
                'type' => TransactionType::OneTime,
                'status' => TransactionStatus::Success,
                'amount' => 5000,
            ],
            [
                'reference' => 'DEMO-PAYSTACK-REFUND',
                'user' => $partial,
                'type' => TransactionType::Refund,
                'status' => TransactionStatus::Success,
                'amount' => 1000,
            ],
        ];

        foreach ($definitions as $definition) {
            $transaction = PaystackTransaction::query()->firstOrCreate(
                ['reference' => $definition['reference']],
                [
                    'user_id' => $definition['user']->id,
                    'family_id' => $family->id,
                    'type' => $definition['type'],
                    'amount' => $definition['amount'],
                    'gross_amount_kobo' => $definition['amount'] * 100,
                    'fee_policy' => 'payer_pays',
                    'status' => TransactionStatus::Initiated,
                    'metadata' => ['demo' => true],
                ],
            );

            if ($transaction->status !== $definition['status']) {
                $transaction->forceFill([
                    'status' => $definition['status'],
                    'verified_at' => in_array($definition['status'], [TransactionStatus::Verified, TransactionStatus::Success], true) ? now() : null,
                    'failed_at' => $definition['status'] === TransactionStatus::Failed ? now() : null,
                    'failure_reason' => $definition['status'] === TransactionStatus::Failed ? 'Demo provider decline.' : null,
                ])->save();
            }
        }
    }

    private function moneyOutAndAdjustments(Family $family, User $recorder): void
    {
        foreach ([
            [3500, 'Demo: Community meeting venue', CarbonImmutable::now()->subMonths(2)->setDay(18)],
            [2200, 'Demo: Member welfare support', CarbonImmutable::now()->subMonth()->setDay(20)],
            [750, 'Demo: Banking and transfer fees', CarbonImmutable::now()->setDay(6)],
        ] as [$amount, $description, $spentAt]) {
            Expense::query()->firstOrCreate(
                ['family_id' => $family->id, 'description' => $description],
                ['amount' => $amount, 'spent_at' => $spentAt, 'recorded_by' => $recorder->id],
            );
        }

        $incorrectExpense = Expense::query()->firstOrCreate(
            ['family_id' => $family->id, 'description' => 'Demo: Incorrect stationery expense'],
            ['amount' => 9000, 'spent_at' => now()->subDays(10), 'recorded_by' => $recorder->id],
        );
        if (! $incorrectExpense->reversal()->exists()) {
            $this->correctExpense->handle(
                $incorrectExpense,
                $recorder,
                'Demo correction: an extra zero was entered.',
                900,
                'Demo: Corrected stationery expense',
                now()->subDays(10),
            );
        }

        $cancelledExpense = Expense::query()->firstOrCreate(
            ['family_id' => $family->id, 'description' => 'Demo: Cancelled event deposit'],
            ['amount' => 5000, 'spent_at' => now()->subDays(20), 'recorded_by' => $recorder->id],
        );
        $this->reverseExpense->handle($cancelledExpense, $recorder, 'Demo event was cancelled and the deposit was refunded.');

        $openingBalance = FundAdjustment::query()->firstOrCreate(
            ['family_id' => $family->id, 'description' => 'Demo: Opening balance'],
            ['amount' => 100000, 'recorded_at' => now()->subMonths(6), 'recorded_by' => $recorder->id],
        );
        FundAdjustment::query()->firstOrCreate(
            ['family_id' => $family->id, 'description' => 'Demo: Bank charge adjustment'],
            ['amount' => -500, 'recorded_at' => now()->subMonth(), 'recorded_by' => $recorder->id],
        );
        $incorrectAdjustment = FundAdjustment::query()->firstOrCreate(
            ['family_id' => $family->id, 'description' => 'Demo: Incorrect opening adjustment'],
            ['amount' => 25000, 'recorded_at' => now()->subMonths(5), 'recorded_by' => $recorder->id],
        );
        if (! $incorrectAdjustment->reversal()->exists()) {
            $this->correctFundAdjustment->handle(
                $incorrectAdjustment,
                $recorder,
                'Demo correction: opening adjustment source was revalidated.',
                20000,
                'Demo: Corrected opening adjustment',
                now()->subMonths(5),
            );
        }

        $this->reverseFundAdjustment->handle($openingBalance, $recorder, 'Demo reversal used to exercise adjustment reversal reporting.');
    }

    private function contributionFor(Family $family, User $member, CarbonImmutable $period): Contribution
    {
        return Contribution::query()
            ->where('family_id', $family->id)
            ->where('user_id', $member->id)
            ->where('year', $period->year)
            ->where('month', $period->month)
            ->firstOrFail();
    }

    private function membership(Family $family, User $member): FamilyMembership
    {
        return FamilyMembership::query()
            ->where('family_id', $family->id)
            ->where('user_id', $member->id)
            ->firstOrFail();
    }
}
