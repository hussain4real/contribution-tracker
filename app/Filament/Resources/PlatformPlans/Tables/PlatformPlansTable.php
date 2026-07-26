<?php

declare(strict_types=1);

namespace App\Filament\Resources\PlatformPlans\Tables;

use App\Models\PlatformPlan;
use App\Support\PlatformPlanCatalog;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TagsColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class PlatformPlansTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->withCount('families')
                ->orderBy('sort_order'))
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('slug')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('price')
                    ->money('NGN')
                    ->sortable(),
                TextColumn::make('max_members')
                    ->label('Max members')
                    ->placeholder('Unlimited')
                    ->sortable(),
                TagsColumn::make('features')
                    ->formatStateUsing(fn (string $state): string => PlatformPlanCatalog::featureLabels()[$state] ?? $state)
                    ->limit(3),
                TextColumn::make('families_count')
                    ->label('Families')
                    ->numeric()
                    ->sortable(),
                IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean(),
                TextColumn::make('sort_order')
                    ->label('Sort')
                    ->sortable(),
            ])
            ->filters([
                TernaryFilter::make('is_active')
                    ->label('Active'),
            ])
            ->recordActions([
                EditAction::make(),
                Action::make('toggleActive')
                    ->label(fn (PlatformPlan $record): string => $record->is_active ? 'Deactivate' : 'Activate')
                    ->color(fn (PlatformPlan $record): string => $record->is_active ? 'danger' : 'success')
                    ->requiresConfirmation()
                    ->action(function (PlatformPlan $record): void {
                        $record->update(['is_active' => ! $record->is_active]);

                        $status = $record->is_active ? 'activated' : 'deactivated';

                        Notification::make()
                            ->success()
                            ->title("Plan \"{$record->name}\" has been {$status}.")
                            ->send();
                    }),
                DeleteAction::make()
                    ->before(function (DeleteAction $action, PlatformPlan $record): void {
                        if (! $record->families()->exists()) {
                            return;
                        }

                        Notification::make()
                            ->danger()
                            ->title('Cannot delete a plan that has families assigned to it.')
                            ->send();

                        $action->halt();
                    }),
            ]);
    }
}
