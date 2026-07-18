<?php

namespace App\Filament\Resources\OutboxEvents;

use App\Filament\Resources\OutboxEvents\Pages\ManageOutboxEvents;
use App\Jobs\DispatchOutboxEventJob;
use App\Models\OutboxEvent;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class OutboxEventResource extends Resource
{
    protected static ?string $model = OutboxEvent::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static string|\UnitEnum|null $navigationGroup = 'Operations';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('type')
                    ->required(),
                TextInput::make('aggregate_type')
                    ->required(),
                TextInput::make('aggregate_id')
                    ->numeric()
                    ->required(),
                Select::make('status')
                    ->options([
                        'pending' => 'pending',
                        'sent' => 'sent',
                        'failed' => 'failed',
                    ])
                    ->required(),
                KeyValue::make('payload_json')
                    ->columnSpanFull(),
                TextInput::make('attempts')
                    ->numeric()
                    ->required(),
                DateTimePicker::make('next_retry_at'),
                Textarea::make('last_error')
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->sortable(),
                TextColumn::make('type')
                    ->searchable(),
                TextColumn::make('aggregate_id')
                    ->label('Call ID')
                    ->sortable(),
                TextColumn::make('status')
                    ->badge(),
                TextColumn::make('attempts')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('next_retry_at')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('last_error')
                    ->limit(50)
                    ->wrap(),
                TextColumn::make('payload_json')
                    ->label('Payload')
                    ->formatStateUsing(fn ($state): string => json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))
                    ->limit(60)
                    ->wrap(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        'pending' => 'pending',
                        'sent' => 'sent',
                        'failed' => 'failed',
                    ]),
            ])
            ->recordActions([
                Action::make('retryNow')
                    ->visible(fn (OutboxEvent $record): bool => $record->status !== 'sent')
                    ->action(function (OutboxEvent $record): void {
                        $record->forceFill([
                            'status' => 'pending',
                            'next_retry_at' => null,
                            'last_error' => null,
                        ])->save();

                        DispatchOutboxEventJob::dispatch($record->id);
                    }),
                Action::make('markFailed')
                    ->color('danger')
                    ->action(fn (OutboxEvent $record) => $record->forceFill(['status' => 'failed'])->save()),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageOutboxEvents::route('/'),
        ];
    }
}
