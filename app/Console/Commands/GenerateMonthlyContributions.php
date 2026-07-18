<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Contribution;
use App\Models\Family;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

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
        $monthOption = $this->option('month') ?? now()->month;

        if (filter_var($monthOption, FILTER_VALIDATE_INT) === false || (int) $monthOption < 1 || (int) $monthOption > 12) {
            $this->error('The --month option must be an integer between 1 and 12.');

            return self::FAILURE;
        }

        $month = (int) $monthOption;
        $familyId = $this->option('family');

        $date = Contribution::dueDateForMonth($year, $month, 1);
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
            $memberships = $family->memberships()
                ->with(['familyCategory', 'categoryAssignments.category', 'user'])
                ->active()
                ->where(function (Builder $query): void {
                    $query->whereNotNull('family_members.family_category_id')
                        ->orWhereNotNull('family_members.category');
                })
                ->get();

            foreach ($memberships as $membership) {
                $member = $membership->user;
                $snapshot = $membership->contributionCategorySnapshot($year, $month);
                $expectedAmount = $snapshot['category_amount'];

                if ($expectedAmount === null || $expectedAmount < 1) {
                    $totalSkipped++;

                    continue;
                }

                $dueDay = $family->due_day ?? Contribution::DUE_DAY;

                $contribution = Contribution::query()->firstOrCreate([
                    'family_id' => $family->id,
                    'user_id' => $member->id,
                    'year' => $year,
                    'month' => $month,
                ], [
                    'expected_amount' => $expectedAmount,
                    'due_date' => Contribution::dueDateForMonth($year, $month, $dueDay),
                    ...$snapshot,
                ]);

                if ($contribution->wasRecentlyCreated) {
                    $totalCreated++;
                } else {
                    $totalSkipped++;
                }
            }
        }

        $this->info("Created {$totalCreated} contributions, skipped {$totalSkipped} (already exist).");

        return self::SUCCESS;
    }
}
