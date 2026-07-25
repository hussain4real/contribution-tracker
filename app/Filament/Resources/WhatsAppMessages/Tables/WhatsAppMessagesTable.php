<?php

declare(strict_types=1);

namespace App\Filament\Resources\WhatsAppMessages\Tables;

use App\Filament\Resources\WhatsAppMessages\WhatsAppMessageResource;
use App\Models\WhatsAppMessage;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

class WhatsAppMessagesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->with(['family:id,name', 'user:id,name'])
                ->latest())
            ->columns([
                TextColumn::make('created_at')
                    ->label('Attempted')
                    ->dateTime('M j, Y g:i A')
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => WhatsAppMessageResource::statusLabel($state))
                    ->color(fn (?string $state): string => WhatsAppMessageResource::statusColor($state))
                    ->sortable(),
                TextColumn::make('template_name')
                    ->label('Template')
                    ->formatStateUsing(fn (?string $state): string => filled($state) ? str($state)->headline()->toString() : 'Direct message')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('to')
                    ->label('Recipient')
                    ->formatStateUsing(fn (?string $state): string => self::maskPhoneNumber($state))
                    ->placeholder('Unknown')
                    ->searchable()
                    ->copyable()
                    ->copyableState(fn (?string $state): ?string => $state),
                TextColumn::make('user.name')
                    ->label('Member')
                    ->searchable()
                    ->placeholder('Unlinked'),
                TextColumn::make('family.name')
                    ->label('Family')
                    ->searchable()
                    ->placeholder('Unlinked'),
                TextColumn::make('error_message')
                    ->label('Failure')
                    ->limit(45)
                    ->tooltip(fn (WhatsAppMessage $record): ?string => $record->error_message)
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('wa_message_id')
                    ->label('Meta message ID')
                    ->searchable()
                    ->copyable()
                    ->placeholder('Not assigned')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->label('Last update')
                    ->since()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->multiple()
                    ->options([
                        'pending' => 'Pending',
                        'sent' => 'Sent',
                        'delivered' => 'Delivered',
                        'read' => 'Read',
                        'failed' => 'Failed',
                    ]),
                SelectFilter::make('template_name')
                    ->label('Template')
                    ->options(fn (): array => WhatsAppMessage::query()
                        ->outbound()
                        ->whereNotNull('template_name')
                        ->distinct()
                        ->orderBy('template_name')
                        ->get(['template_name'])
                        ->keyBy('template_name')
                        ->map(fn (WhatsAppMessage $message): string => str($message->template_name)->headline()->toString())
                        ->all()),
                SelectFilter::make('family')
                    ->relationship('family', 'name')
                    ->searchable()
                    ->preload(),
                Filter::make('attempted_at')
                    ->schema([
                        DatePicker::make('from')->label('Attempted from'),
                        DatePicker::make('until')->label('Attempted until'),
                    ])
                    ->columns(2)
                    ->query(function (Builder $query, array $data): Builder {
                        $from = $data['from'] ?? null;
                        $until = $data['until'] ?? null;

                        if (is_string($from) && filled($from)) {
                            $query->whereRaw('created_at >= ?', [Carbon::parse($from)->startOfDay()]);
                        }

                        if (is_string($until) && filled($until)) {
                            $query->whereRaw('created_at <= ?', [Carbon::parse($until)->endOfDay()]);
                        }

                        return $query;
                    }),
            ])
            ->recordActions([
                ViewAction::make(),
            ]);
    }

    private static function maskPhoneNumber(?string $phoneNumber): string
    {
        if (mb_strlen($phoneNumber ?? '') <= 4) {
            return $phoneNumber ?? '';
        }

        return '•••• '.mb_substr($phoneNumber, -4);
    }
}
