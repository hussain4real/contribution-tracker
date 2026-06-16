<?php

declare(strict_types=1);

namespace App\Filament\Resources\Families\Schemas;

use App\Enums\Role;
use App\Models\Family;
use App\Models\User;
use App\Support\PlatformFamilySummary;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class FamilyInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Family')
                    ->columns(3)
                    ->schema([
                        TextEntry::make('name'),
                        TextEntry::make('slug'),
                        TextEntry::make('currency'),
                        TextEntry::make('due_day')->label('Due day'),
                        TextEntry::make('owner.name')->label('Owner')->placeholder('None'),
                        TextEntry::make('platformPlan.name')->label('Plan')->placeholder('None'),
                        IconEntry::make('is_suspended')
                            ->label('Suspended')
                            ->boolean()
                            ->state(fn (Family $record): bool => $record->isSuspended()),
                        TextEntry::make('created_at')->date(),
                        TextEntry::make('suspended_at')->date()->placeholder('Not suspended'),
                    ]),
                Section::make('Financial summary')
                    ->columns(3)
                    ->schema([
                        TextEntry::make('total_contributions')
                            ->label('Contributions')
                            ->numeric()
                            ->state(fn (Family $record): int => PlatformFamilySummary::for($record)['total_contributions']),
                        TextEntry::make('total_collected')
                            ->label('Collected')
                            ->money('NGN')
                            ->state(fn (Family $record): int => PlatformFamilySummary::for($record)['total_collected']),
                        TextEntry::make('total_expected')
                            ->label('Expected')
                            ->money('NGN')
                            ->state(fn (Family $record): int => PlatformFamilySummary::for($record)['total_expected']),
                        TextEntry::make('collection_rate')
                            ->label('Collection rate')
                            ->suffix('%')
                            ->state(fn (Family $record): float|int => PlatformFamilySummary::for($record)['collection_rate']),
                        TextEntry::make('active_members')
                            ->label('Active members')
                            ->numeric()
                            ->state(fn (Family $record): int => PlatformFamilySummary::for($record)['active_members']),
                        TextEntry::make('archived_members')
                            ->label('Archived members')
                            ->numeric()
                            ->state(fn (Family $record): int => PlatformFamilySummary::for($record)['archived_members']),
                    ]),
                Section::make('Categories')
                    ->schema([
                        RepeatableEntry::make('categories')
                            ->columns(2)
                            ->schema([
                                TextEntry::make('name'),
                                TextEntry::make('monthly_amount')->money('NGN'),
                            ]),
                    ]),
                Section::make('Members')
                    ->schema([
                        RepeatableEntry::make('members')
                            ->columns(4)
                            ->schema([
                                TextEntry::make('name'),
                                TextEntry::make('email'),
                                TextEntry::make('role')->formatStateUsing(fn (?Role $state): string => $state?->label() ?? ''),
                                IconEntry::make('is_active')
                                    ->label('Active')
                                    ->boolean()
                                    ->state(fn (User $record): bool => $record->archived_at === null),
                            ]),
                    ]),
            ]);
    }
}
