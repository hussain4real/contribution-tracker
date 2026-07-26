<?php

declare(strict_types=1);

namespace App\Filament\Resources\Families\Pages;

use App\Filament\Resources\Families\FamilyResource;
use App\Support\PlatformCsvExports;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;

class ListFamilies extends ListRecords
{
    protected static string $resource = FamilyResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('exportFamilies')
                ->label('Export families')
                ->action(fn () => PlatformCsvExports::families()),
        ];
    }
}
