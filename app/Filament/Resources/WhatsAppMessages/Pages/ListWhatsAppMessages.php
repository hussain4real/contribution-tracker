<?php

declare(strict_types=1);

namespace App\Filament\Resources\WhatsAppMessages\Pages;

use App\Filament\Resources\WhatsAppMessages\WhatsAppMessageResource;
use App\Filament\Resources\WhatsAppMessages\Widgets\WhatsAppDeliveryStats;
use Filament\Resources\Pages\ListRecords;
use Filament\Widgets\Widget;

class ListWhatsAppMessages extends ListRecords
{
    protected static string $resource = WhatsAppMessageResource::class;

    /**
     * @return array<class-string<Widget>>
     */
    protected function getHeaderWidgets(): array
    {
        return [
            WhatsAppDeliveryStats::class,
        ];
    }
}
