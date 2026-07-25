<?php

declare(strict_types=1);

namespace App\Filament\Resources\WhatsAppMessages\Schemas;

use App\Filament\Resources\WhatsAppMessages\WhatsAppMessageResource;
use App\Models\WhatsAppMessage;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class WhatsAppMessageInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Delivery')
                    ->columns(4)
                    ->schema([
                        TextEntry::make('status')
                            ->badge()
                            ->formatStateUsing(fn (?string $state): string => WhatsAppMessageResource::statusLabel($state))
                            ->color(fn (?string $state): string => WhatsAppMessageResource::statusColor($state)),
                        TextEntry::make('created_at')
                            ->label('Attempted')
                            ->dateTime('M j, Y g:i:s A'),
                        TextEntry::make('updated_at')
                            ->label('Last update')
                            ->dateTime('M j, Y g:i:s A'),
                        TextEntry::make('read_at')
                            ->label('Read at')
                            ->dateTime('M j, Y g:i:s A')
                            ->placeholder('Not read'),
                    ]),
                Section::make('Message')
                    ->columns(3)
                    ->schema([
                        TextEntry::make('template_name')
                            ->label('Template')
                            ->formatStateUsing(fn (?string $state): string => filled($state) ? str($state)->headline()->toString() : 'Direct message'),
                        TextEntry::make('type')
                            ->formatStateUsing(fn (?string $state): string => ucfirst($state ?? 'Unknown')),
                        TextEntry::make('to')
                            ->label('Recipient')
                            ->copyable(),
                        TextEntry::make('user.name')
                            ->label('Member')
                            ->placeholder('Unlinked'),
                        TextEntry::make('family.name')
                            ->label('Family')
                            ->placeholder('Unlinked'),
                        TextEntry::make('wa_message_id')
                            ->label('Meta message ID')
                            ->copyable()
                            ->placeholder('Not assigned'),
                        TextEntry::make('body')
                            ->columnSpanFull()
                            ->placeholder('Template message; body was not stored.'),
                    ]),
                Section::make('Failure details')
                    ->columns(2)
                    ->visible(fn (WhatsAppMessage $record): bool => $record->status === 'failed' || filled($record->error_message))
                    ->schema([
                        TextEntry::make('error_code')
                            ->label('Error code')
                            ->copyable()
                            ->placeholder('Not provided'),
                        TextEntry::make('error_message')
                            ->label('Error message')
                            ->placeholder('Not provided'),
                    ]),
                Section::make('Provider payload')
                    ->collapsed()
                    ->schema([
                        KeyValueEntry::make('payload')
                            ->state(fn (WhatsAppMessage $record): array => collect($record->payload ?? [])
                                ->map(fn (mixed $value): string => is_array($value)
                                    ? self::encodePayloadValue($value)
                                    : match (true) {
                                        is_bool($value) => $value ? 'true' : 'false',
                                        is_scalar($value) => (string) $value,
                                        default => '',
                                    })
                                ->all())
                            ->hiddenLabel()
                            ->keyLabel('Field')
                            ->valueLabel('Value')
                            ->placeholder('No provider payload stored.'),
                    ]),
            ]);
    }

    /**
     * @param  array<array-key, mixed>  $value
     */
    private static function encodePayloadValue(array $value): string
    {
        return json_encode(
            $value,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR,
        );
    }
}
