<?php

declare(strict_types=1);

namespace App\Filament\Resources\PlatformPlans;

use App\Filament\Resources\PlatformPlans\Pages\CreatePlatformPlan;
use App\Filament\Resources\PlatformPlans\Pages\EditPlatformPlan;
use App\Filament\Resources\PlatformPlans\Pages\ListPlatformPlans;
use App\Filament\Resources\PlatformPlans\Schemas\PlatformPlanForm;
use App\Filament\Resources\PlatformPlans\Tables\PlatformPlansTable;
use App\Models\PlatformPlan;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class PlatformPlanResource extends Resource
{
    protected static ?string $model = PlatformPlan::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleGroup;

    protected static ?string $navigationLabel = 'Plans';

    protected static ?string $slug = 'plans';

    public static function form(Schema $schema): Schema
    {
        return PlatformPlanForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PlatformPlansTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPlatformPlans::route('/'),
            'create' => CreatePlatformPlan::route('/create'),
            'edit' => EditPlatformPlan::route('/{record}/edit'),
        ];
    }
}
