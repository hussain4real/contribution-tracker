<?php

declare(strict_types=1);

namespace App\Filament\Resources\Families\Pages;

use App\Filament\Resources\Families\FamilyResource;
use App\Models\Family;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

class ViewFamily extends ViewRecord
{
    protected static string $resource = FamilyResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('suspend')
                ->label('Suspend family')
                ->color('danger')
                ->requiresConfirmation()
                ->visible(fn (Family $record): bool => ! $record->isSuspended())
                ->action(function (Family $record): void {
                    $record->update(['suspended_at' => now()]);

                    Notification::make()
                        ->success()
                        ->title("Family \"{$record->name}\" has been suspended.")
                        ->send();
                }),
            Action::make('unsuspend')
                ->label('Unsuspend family')
                ->color('success')
                ->requiresConfirmation()
                ->visible(fn (Family $record): bool => $record->isSuspended())
                ->action(function (Family $record): void {
                    $record->update(['suspended_at' => null]);

                    Notification::make()
                        ->success()
                        ->title("Family \"{$record->name}\" has been unsuspended.")
                        ->send();
                }),
        ];
    }
}
