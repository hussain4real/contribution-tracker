<?php

namespace App\Console\Commands;

use App\Enums\Role;
use App\Models\Contribution;
use App\Models\Payment;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Console\Command;

class BackfillTotals extends Command
{
    protected $signature = 'app:backfill-totals
        {--total= : Total amount in Naira to distribute equally among all active paying members}';

    protected $description = 'Backfill historical contributions and payments by distributing a lump total equally among all active paying members, filling backward from the previous month.';

    public function handle(): int
    {
        $total = (int) $this->option('total');

        if ($total <= 0) {
            $this->error('Total must be a positive amount. Usage: --total=240000');

            return self::FAILURE;
        }

        $members = User::query()->active()->payingMembers()->get();

        if ($members->isEmpty()) {
            $this->error('No active paying members found. Create members first before running this command.');

            return self::FAILURE;
        }

        $recorder = User::query()->where('role', Role::SuperAdmin)->first();

        if (! $recorder) {
            $this->error('No Super Admin found. A Super Admin account is required to record backfill payments.');

            return self::FAILURE;
        }

        $perMemberShare = intdiv($total, $members->count());
        $remainder = $total - ($perMemberShare * $members->count());

        if ($perMemberShare <= 0) {
            $this->error("Total ₦{$total} is too small to distribute among {$members->count()} members.");

            return self::FAILURE;
        }

        $this->info('Distributing ₦'.number_format($total)." equally among {$members->count()} members (₦".number_format($perMemberShare).' each).');

        if ($remainder > 0) {
            $this->warn('₦'.number_format($remainder).' remainder will not be allocated (not evenly divisible).');
        }

        $summaryRows = [];

        foreach ($members as $member) {
            $result = $this->backfillMember($member, $perMemberShare, $recorder);
            $summaryRows[] = $result;
        }

        $this->newLine();
        $this->table(
            ['Member', 'Category', 'Monthly Amount', 'Months Covered', 'Total Allocated', 'Unallocated'],
            $summaryRows
        );

        $totalAllocated = collect($summaryRows)->sum(fn (array $row) => (int) str_replace(['₦', ','], '', $row[4]));
        $this->newLine();
        $this->info('Backfill complete. Total allocated: ₦'.number_format($totalAllocated)." across {$members->count()} members.");

        return self::SUCCESS;
    }

    /**
     * Backfill contributions and payments for a single member, going backward from previous month.
     *
     * @return array{string, string, string, int, string, string}
     */
    private function backfillMember(User $member, int $shareAmount, User $recorder): array
    {
        $monthlyAmount = $member->category->monthlyAmount();
        $remaining = $shareAmount;
        $monthsCovered = 0;
        $totalAllocated = 0;

        $date = now()->subMonth()->startOfMonth();

        while ($remaining > 0) {
            $existingContribution = Contribution::query()
                ->forUser($member->id)
                ->forMonth($date->year, $date->month)
                ->first();

            if ($existingContribution) {
                $date = $date->copy()->subMonth();

                continue;
            }

            $amountForMonth = min($remaining, $monthlyAmount);

            $contribution = Contribution::create([
                'user_id' => $member->id,
                'year' => $date->year,
                'month' => $date->month,
                'expected_amount' => $monthlyAmount,
            ]);

            Payment::create([
                'contribution_id' => $contribution->id,
                'amount' => $amountForMonth,
                'paid_at' => Carbon::createFromDate($date->year, $date->month, 1)->endOfMonth(),
                'recorded_by' => $recorder->id,
                'notes' => 'Historical backfill',
            ]);

            $totalAllocated += $amountForMonth;
            $remaining -= $amountForMonth;
            $monthsCovered++;

            $date = $date->copy()->subMonth();
        }

        return [
            $member->name,
            $member->category->label(),
            '₦'.number_format($monthlyAmount),
            $monthsCovered,
            '₦'.number_format($totalAllocated),
            '₦'.number_format($shareAmount - $totalAllocated),
        ];
    }
}
