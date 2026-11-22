<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ExpenseTemplate;
use App\Services\RecurringExpenseDrafts;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('expenses:generate-drafts {--family= : Limit generation to one family} {--date= : Process due dates through this UTC calendar date}')]
#[Description('Generate due monthly expense request drafts')]
class GenerateExpenseDrafts extends Command
{
    public function handle(RecurringExpenseDrafts $drafts): int
    {
        $family = $this->option('family');
        $date = $this->option('date') ?? now('UTC')->toDateString();
        if (($family !== null && filter_var($family, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false)
            || ! preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $date, $parts)
            || ! checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
            $this->error('Use a valid family ID and a date in YYYY-MM-DD format.');

            return self::INVALID;
        }

        $today = CarbonImmutable::parse($date, 'UTC');
        $generated = 0;
        $templates = ExpenseTemplate::query()->where('next_run_on', '<=', $date)
            ->when($family !== null, fn ($query) => $query->where('family_id', (int) $family))
            ->lazyById(100);
        foreach ($templates as $template) {
            $generated += (int) $drafts->generate($template, $today);
        }
        $this->info("Generated {$generated} expense request draft(s).");

        return self::SUCCESS;
    }
}
