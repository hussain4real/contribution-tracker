<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Models\Contribution;
use App\Models\Expense;
use App\Models\Family;
use App\Models\Payment;
use App\Models\User;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class PlatformStatsOverview extends StatsOverviewWidget
{
    protected function getStats(): array
    {
        return [
            Stat::make('Total families', Family::count()),
            Stat::make('New families this month', Family::query()
                ->where('created_at', '>=', now()->startOfMonth())
                ->count()),
            Stat::make('Total users', User::count()),
            Stat::make('Active users', User::active()->count()),
            Stat::make('Archived users', User::archived()->count()),
            Stat::make('Total contributions', Contribution::count()),
            Stat::make('Total payments', '₦'.number_format((int) Payment::sum('amount'))),
            Stat::make('Total expenses', '₦'.number_format((int) Expense::sum('amount'))),
        ];
    }
}
