<?php

declare(strict_types=1);

namespace App\Filament\Resources\WhatsAppMessages\Widgets;

use App\Models\WhatsAppMessage;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Database\Eloquent\Builder;

class WhatsAppDeliveryStats extends StatsOverviewWidget
{
    protected ?string $pollingInterval = '15s';

    protected function getStats(): array
    {
        $today = $this->deliverySummary(
            WhatsAppMessage::query()
                ->outbound()
                ->where('created_at', '>=', now()->startOfDay()),
        );
        $lastSevenDays = $this->deliverySummary(
            WhatsAppMessage::query()
                ->outbound()
                ->where('created_at', '>=', now()->subDays(6)->startOfDay()),
        );
        $confirmedRate = $lastSevenDays['total'] > 0
            ? round(($lastSevenDays['confirmed'] / $lastSevenDays['total']) * 100, 1)
            : 0;

        return [
            Stat::make('Attempts today', number_format($today['total']))
                ->description('Outbound WhatsApp notifications')
                ->descriptionIcon(Heroicon::OutlinedPaperAirplane),
            Stat::make('Confirmed today', number_format($today['confirmed']))
                ->description('Delivered or read')
                ->descriptionIcon(Heroicon::OutlinedCheckCircle)
                ->color('success'),
            Stat::make('Failed today', number_format($today['failed']))
                ->description($today['failed'] > 0 ? 'Requires investigation' : 'No failures reported')
                ->descriptionIcon(Heroicon::OutlinedExclamationTriangle)
                ->color($today['failed'] > 0 ? 'danger' : 'success'),
            Stat::make('7-day confirmation rate', number_format($confirmedRate, 1).'%')
                ->description(number_format($lastSevenDays['confirmed']).' of '.number_format($lastSevenDays['total']).' confirmed')
                ->descriptionIcon(Heroicon::OutlinedClock)
                ->color($lastSevenDays['total'] === 0 ? 'gray' : ($confirmedRate >= 90 ? 'success' : ($confirmedRate >= 70 ? 'warning' : 'danger'))),
        ];
    }

    /**
     * @param  Builder<WhatsAppMessage>  $query
     * @return array{total: int, confirmed: int, failed: int}
     */
    private function deliverySummary(Builder $query): array
    {
        $summary = $query
            ->selectRaw('COUNT(*) as total')
            ->selectRaw("SUM(CASE WHEN status IN ('delivered', 'read') THEN 1 ELSE 0 END) as confirmed")
            ->selectRaw("SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed")
            ->first();

        return [
            'total' => $this->normalizeCount($summary?->getAttribute('total')),
            'confirmed' => $this->normalizeCount($summary?->getAttribute('confirmed')),
            'failed' => $this->normalizeCount($summary?->getAttribute('failed')),
        ];
    }

    private function normalizeCount(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}
