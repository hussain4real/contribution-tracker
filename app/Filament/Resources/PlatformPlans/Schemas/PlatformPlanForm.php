<?php

declare(strict_types=1);

namespace App\Filament\Resources\PlatformPlans\Schemas;

use App\Models\PlatformPlan;
use App\Support\PlatformPlanCatalog;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Validation\Rule;

class PlatformPlanForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Plan')
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('slug')
                            ->required()
                            ->maxLength(255)
                            ->rules(fn (?PlatformPlan $record): array => [
                                Rule::unique('platform_plans', 'slug')->ignore($record?->id),
                            ]),
                        TextInput::make('price')
                            ->required()
                            ->integer()
                            ->minValue(0),
                        TextInput::make('max_members')
                            ->label('Max members')
                            ->integer()
                            ->minValue(1),
                        TextInput::make('paystack_plan_code')
                            ->label('Paystack plan code')
                            ->maxLength(255),
                        TextInput::make('sort_order')
                            ->required()
                            ->integer()
                            ->minValue(0)
                            ->default(0),
                        Toggle::make('is_active')
                            ->label('Active')
                            ->default(true),
                        CheckboxList::make('features')
                            ->options(PlatformPlanCatalog::featureLabels())
                            ->columns(2)
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
