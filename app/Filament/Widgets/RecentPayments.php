<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Models\Payment;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

class RecentPayments extends TableWidget
{
    protected static ?string $heading = 'Recent payments';

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => Payment::query()
                ->with(['contribution.user', 'recorder'])
                ->latest())
            ->columns([
                TextColumn::make('amount')->money('NGN'),
                TextColumn::make('contribution.user.name')->label('Member')->placeholder('Unknown'),
                TextColumn::make('recorder.name')->label('Recorded by')->placeholder('Unknown'),
                TextColumn::make('created_at')->date(),
            ])
            ->defaultPaginationPageOption(5);
    }
}
