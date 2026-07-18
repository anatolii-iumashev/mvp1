<?php

namespace App\Filament\Resources\Calls;

use App\Filament\Resources\Calls\Pages\ManageCalls;
use App\Jobs\DispatchOutboxEventJob;
use App\Models\Call;
use App\Models\OutboxEvent;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class CallResource extends Resource
{
    protected static ?string $model = Call::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static string|\UnitEnum|null $navigationGroup = 'Operations';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('phone')
                    ->required()
                    ->maxLength(255),
                Select::make('status')
                    ->options([
                        'new' => 'new',
                        'assigned' => 'assigned',
                        'dispatched' => 'dispatched',
                        'failed' => 'failed',
                    ])
                    ->required(),
                Select::make('client_id')
                    ->relationship('client', 'phone')
                    ->searchable()
                    ->preload(),
                Select::make('operator_id')
                    ->relationship('operator', 'name')
                    ->searchable()
                    ->preload(),
                DateTimePicker::make('assigned_at'),
                DateTimePicker::make('dispatched_at'),
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
                TextColumn::make('phone')
                    ->searchable(),
                TextColumn::make('status')
                    ->badge(),
                TextColumn::make('client.phone')
                    ->label('Client'),
                TextColumn::make('operator.name')
                    ->label('Operator'),
                TextColumn::make('assigned_at')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('dispatched_at')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('attempts_assign')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('last_error')
                    ->limit(50)
                    ->wrap(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        'new' => 'new',
                        'assigned' => 'assigned',
                        'dispatched' => 'dispatched',
                        'failed' => 'failed',
                    ]),
                SelectFilter::make('operator')
                    ->relationship('operator', 'name'),
                Filter::make('created_at')
                    ->schema([
                        DateTimePicker::make('from'),
                        DateTimePicker::make('until'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['from'] ?? null, fn (Builder $query, $from): Builder => $query->where('created_at', '>=', $from))
                            ->when($data['until'] ?? null, fn (Builder $query, $until): Builder => $query->where('created_at', '<=', $until));
                    }),
            ])
            ->recordActions([
                Action::make('retryDispatch')
                    ->visible(fn (Call $record): bool => in_array($record->status, ['assigned', 'failed'], true))
                    ->action(function (Call $record): void {
                        $event = OutboxEvent::query()->firstOrCreate(
                            [
                                'type' => 'call.assigned',
                                'aggregate_type' => 'call',
                                'aggregate_id' => $record->id,
                            ],
                            [
                                'payload_json' => [
                                    'call_id' => $record->id,
                                    'operator_id' => $record->operator_id,
                                ],
                                'status' => 'pending',
                                'attempts' => 0,
                            ],
                        );

                        $event->forceFill([
                            'status' => 'pending',
                            'next_retry_at' => null,
                            'last_error' => null,
                            'payload_json' => [
                                'call_id' => $record->id,
                                'operator_id' => $record->operator_id,
                            ],
                        ])->save();

                        DispatchOutboxEventJob::dispatch($event->id);
                    }),
                Action::make('resetToNew')
                    ->visible(fn (Call $record): bool => $record->status !== 'new')
                    ->requiresConfirmation()
                    ->action(function (Call $record): void {
                        if ($record->operator !== null) {
                            $record->operator->forceFill([
                                'available' => true,
                            ])->save();
                        }

                        $record->forceFill([
                            'status' => 'new',
                            'operator_id' => null,
                            'assigned_at' => null,
                            'dispatched_at' => null,
                            'last_error' => null,
                        ])->save();
                    }),
                Action::make('markFailed')
                    ->color('danger')
                    ->action(fn (Call $record) => $record->forceFill(['status' => 'failed'])->save()),
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
            'index' => ManageCalls::route('/'),
        ];
    }
}
