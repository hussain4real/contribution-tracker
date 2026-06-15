<?php

declare(strict_types=1);

namespace App\Filament\Resources\Families\Tables;

use App\Models\Family;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class FamiliesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->with(['owner', 'platformPlan'])
                ->withCount('members')
                ->latest())
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('slug')
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('owner.name')
                    ->label('Owner')
                    ->searchable()
                    ->placeholder('None'),
                TextColumn::make('platformPlan.name')
                    ->label('Plan')
                    ->placeholder('None')
                    ->toggleable(),
                TextColumn::make('currency')
                    ->sortable(),
                TextColumn::make('due_day')
                    ->label('Due day')
                    ->sortable(),
                TextColumn::make('members_count')
                    ->label('Members')
                    ->numeric()
                    ->sortable(),
                IconColumn::make('is_suspended')
                    ->label('Suspended')
                    ->boolean()
                    ->state(fn (Family $record): bool => $record->isSuspended()),
                TextColumn::make('created_at')
                    ->date()
                    ->sortable(),
            ])
            ->filters([
                TernaryFilter::make('suspended')
                    ->label('Suspended')
                    ->queries(
                        true: fn (Builder $query): Builder => $query->whereNotNull('suspended_at'),
                        false: fn (Builder $query): Builder => $query->whereNull('suspended_at'),
                    ),
            ])
            ->recordActions([
                ViewAction::make(),
            ]);
    }
}
