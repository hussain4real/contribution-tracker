<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\ScoreFamilyPaymentRisk;
use App\Models\Family;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('payment-risk:score {family : Family ID or slug} {--period= : Contribution period in YYYY-MM format}')]
#[Description('Store advisory payment-risk predictions for one family and period')]
class ScorePaymentRisk extends Command
{
    public function handle(ScoreFamilyPaymentRisk $scoreFamily): int
    {
        $familyKey = $this->argument('family');
        $period = $this->option('period') ?? now()->format('Y-m');

        if (trim($familyKey) === '') {
            $this->error('A family ID or slug is required.');

            return self::FAILURE;
        }

        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period) !== 1) {
            $this->error('The --period option must use YYYY-MM format.');

            return self::FAILURE;
        }

        $familyQuery = Family::query()->where('slug', $familyKey);

        if (ctype_digit($familyKey)) {
            $familyQuery->orWhere('id', (int) $familyKey);
        }

        $family = $familyQuery->first();

        if (! $family instanceof Family) {
            $this->error('The requested family was not found.');

            return self::FAILURE;
        }

        [$year, $month] = array_map('intval', explode('-', $period));

        try {
            $result = $scoreFamily->handle($family, $year, $month);
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->table(['Created', 'Existing', 'Unavailable', 'Pooled', 'Scored'], [[
            $result['created'],
            $result['existing'],
            $result['unavailable'],
            $result['pooled'],
            $result['scored'],
        ]]);

        return self::SUCCESS;
    }
}
