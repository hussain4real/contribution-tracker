<?php

declare(strict_types=1);

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Facades\Password;

class ViewUser extends ViewRecord
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('sendPasswordReset')
                ->label('Send password reset')
                ->requiresConfirmation()
                ->action(function (User $record): void {
                    Password::sendResetLink(['email' => $record->email]);

                    Notification::make()
                        ->success()
                        ->title("Password reset email sent to {$record->email}.")
                        ->send();
                }),
            Action::make('impersonate')
                ->label('Impersonate')
                ->color('warning')
                ->requiresConfirmation()
                ->visible(fn (User $record): bool => ! $record->isSuperAdmin())
                ->action(function (User $record) {
                    session()->put('impersonating_from', auth()->id());

                    auth()->login($record);

                    return redirect()->route('dashboard')->with('success', "Now impersonating {$record->name}.");
                }),
        ];
    }
}
