<?php

declare(strict_types=1);

namespace App\Filament\Resources\Families\Pages;

use App\Filament\Resources\Families\FamilyResource;
use App\Models\Family;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
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
            Action::make('placeLegalHold')
                ->label('Place legal hold')
                ->color('warning')
                ->schema([
                    Textarea::make('reason')
                        ->label('Reason for legal hold')
                        ->required()
                        ->maxLength(1000),
                ])
                ->visible(fn (Family $record): bool => $record->isArchived() && ! $record->isOnLegalHold())
                ->action(function (array $data, Family $record): void {
                    $record->update([
                        'legal_hold_at' => now(),
                        'legal_hold_by' => auth()->id(),
                        'legal_hold_reason' => $data['reason'],
                    ]);

                    Notification::make()
                        ->success()
                        ->title("Legal hold placed on \"{$record->name}\".")
                        ->send();
                }),
            Action::make('releaseLegalHold')
                ->label('Release legal hold')
                ->color('success')
                ->requiresConfirmation()
                ->visible(fn (Family $record): bool => $record->isOnLegalHold())
                ->action(function (Family $record): void {
                    $record->update([
                        'legal_hold_at' => null,
                        'legal_hold_by' => null,
                        'legal_hold_reason' => null,
                    ]);

                    Notification::make()
                        ->success()
                        ->title("Legal hold released from \"{$record->name}\".")
                        ->send();
                }),
        ];
    }
}
