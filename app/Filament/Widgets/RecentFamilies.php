<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Filament\Resources\Families\FamilyResource;
use App\Models\Family;
use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

class RecentFamilies extends TableWidget
{
    protected static ?string $heading = 'Recent families';

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => Family::query()
                ->withCount('members')
                ->latest())
            ->columns([
                TextColumn::make('name'),
                TextColumn::make('currency'),
                TextColumn::make('members_count')->label('Members')->numeric(),
                TextColumn::make('created_at')->date(),
            ])
            ->recordActions([
                Action::make('view')
                    ->url(fn (Family $record): string => FamilyResource::getUrl('view', ['record' => $record])),
            ])
            ->defaultPaginationPageOption(10);
    }
}
