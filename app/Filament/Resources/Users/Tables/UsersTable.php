<?php

declare(strict_types=1);

namespace App\Filament\Resources\Users\Tables;

use App\Enums\Role;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Password;

class UsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->with(['family', 'familyCategory:id,name,monthly_amount'])
                ->latest())
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('email')
                    ->searchable()
                    ->copyable(),
                TextColumn::make('role')
                    ->badge()
                    ->formatStateUsing(fn (Role $state): string => $state->label())
                    ->sortable(),
                TextColumn::make('category')
                    ->label('Category')
                    ->state(fn (User $record): ?string => $record->familyCategory->name ?? $record->category?->label())
                    ->placeholder('None'),
                TextColumn::make('family.name')
                    ->label('Family')
                    ->searchable()
                    ->placeholder('None'),
                IconColumn::make('is_super_admin')
                    ->label('Super admin')
                    ->boolean(),
                IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean()
                    ->state(fn (User $record): bool => $record->archived_at === null),
                TextColumn::make('created_at')
                    ->date()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('role')
                    ->options(collect(Role::cases())->mapWithKeys(fn (Role $role): array => [
                        $role->value => $role->label(),
                    ])->all()),
                TernaryFilter::make('active')
                    ->queries(
                        true: fn (Builder $query): Builder => $query->whereNull('archived_at'),
                        false: fn (Builder $query): Builder => $query->whereNotNull('archived_at'),
                    ),
                TernaryFilter::make('is_super_admin')
                    ->label('Super admin'),
            ])
            ->recordActions([
                ViewAction::make(),
                Action::make('sendPasswordReset')
                    ->label('Send reset')
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
            ]);
    }
}
