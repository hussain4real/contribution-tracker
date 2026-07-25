<?php

declare(strict_types=1);

namespace App\Filament\Resources\WhatsAppMessages;

use App\Filament\Resources\WhatsAppMessages\Pages\ListWhatsAppMessages;
use App\Filament\Resources\WhatsAppMessages\Pages\ViewWhatsAppMessage;
use App\Filament\Resources\WhatsAppMessages\Schemas\WhatsAppMessageInfolist;
use App\Filament\Resources\WhatsAppMessages\Tables\WhatsAppMessagesTable;
use App\Models\WhatsAppMessage;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class WhatsAppMessageResource extends Resource
{
    protected static ?string $model = WhatsAppMessage::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleLeftRight;

    protected static string|UnitEnum|null $navigationGroup = 'Communications';

    protected static ?string $navigationLabel = 'WhatsApp Notifications';

    protected static ?string $modelLabel = 'WhatsApp notification';

    protected static ?string $pluralModelLabel = 'WhatsApp notifications';

    public static function infolist(Schema $schema): Schema
    {
        return WhatsAppMessageInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return WhatsAppMessagesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    /**
     * @return Builder<WhatsAppMessage>
     */
    public static function getEloquentQuery(): Builder
    {
        return WhatsAppMessage::query()->outbound();
    }

    public static function statusLabel(?string $status): string
    {
        return match ($status) {
            'pending' => 'Pending',
            'sent' => 'Sent',
            'delivered' => 'Delivered',
            'read' => 'Read',
            'failed' => 'Failed',
            default => ucfirst($status ?? 'Unknown'),
        };
    }

    public static function statusColor(?string $status): string
    {
        return match ($status) {
            'sent' => 'info',
            'delivered', 'read' => 'success',
            'failed' => 'danger',
            default => 'gray',
        };
    }

    public static function getPages(): array
    {
        return [
            'index' => ListWhatsAppMessages::route('/'),
            'view' => ViewWhatsAppMessage::route('/{record}'),
        ];
    }
}
