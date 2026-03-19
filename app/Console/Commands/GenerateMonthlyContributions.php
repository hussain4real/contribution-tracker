<?php

namespace App\Console\Commands;

use App\Models\Contribution;
use App\Models\Family;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('contributions:generate {--month= : Month (1-12)} {--year= : Year} {--family= : Specific family ID}')]
#[Description('Generate monthly contribution records for all active family members')]
class GenerateMonthlyContributions extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $year = (int) ($this->option('year') ?? now()->year);
        $month = (int) ($this->option('month') ?? now()->month);
        $familyId = $this->option('family');

        $date = Carbon::createFromDate($year, $month, 1);
        $periodLabel = $date->format('F Y');

        $this->info("Generating contributions for {$periodLabel}...");

        $familiesQuery = Family::query()->has('members');

        if ($familyId) {
            $familiesQuery->where('id', $familyId);
        }

        $families = $familiesQuery->get();
        $totalCreated = 0;
        $totalSkipped = 0;

        foreach ($families as $family) {
            $members = User::query()
                ->where('family_id', $family->id)
                ->active()
                ->whereNotNull('category')
                ->get();

            foreach ($members as $member) {
                $exists = Contribution::query()
                    ->where('family_id', $family->id)
                    ->where('user_id', $member->id)
                    ->where('year', $year)
                    ->where('month', $month)
                    ->exists();

                if ($exists) {
                    $totalSkipped++;

                    continue;
                }

                $dueDay = $family->due_day ?? Contribution::DUE_DAY;

                Contribution::create([
                    'family_id' => $family->id,
                    'user_id' => $member->id,
                    'year' => $year,
                    'month' => $month,
                    'expected_amount' => $member->getMonthlyAmount() ?? 0,
                    'due_date' => Carbon::createFromDate($year, $month, min($dueDay, $date->daysInMonth)),
                ]);

                $totalCreated++;
            }
        }

        $this->info("Created {$totalCreated} contributions, skipped {$totalSkipped} (already exist).");

        return self::SUCCESS;
    }
}
