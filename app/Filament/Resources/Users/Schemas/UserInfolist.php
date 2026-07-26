<?php

declare(strict_types=1);

namespace App\Filament\Resources\Users\Schemas;

use App\Enums\Role;
use App\Models\User;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class UserInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('User')
                    ->columns(3)
                    ->schema([
                        TextEntry::make('name'),
                        TextEntry::make('email'),
                        TextEntry::make('role')->formatStateUsing(fn (?Role $state): string => $state?->label() ?? ''),
                        TextEntry::make('family.name')->label('Family')->placeholder('None'),
                        TextEntry::make('category')
                            ->label('Category')
                            ->state(fn (User $record): ?string => $record->familyCategory->name ?? $record->category?->label())
                            ->placeholder('None'),
                        IconEntry::make('is_super_admin')->label('Super admin')->boolean(),
                        IconEntry::make('is_active')
                            ->label('Active')
                            ->boolean()
                            ->state(fn (User $record): bool => $record->archived_at === null),
                        TextEntry::make('created_at')->date(),
                        TextEntry::make('archived_at')->date()->placeholder('Not archived'),
                    ]),
            ]);
    }
}
