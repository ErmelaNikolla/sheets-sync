<?php

namespace App\Filament\Resources\SyncRuns\RelationManagers;

use App\Enums\SyncStatus;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Str;

class ItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'items';

    protected static ?string $title = 'Mappings processed';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('spreadsheet_name')
                    ->label('Spreadsheet')
                    ->description(fn ($record) => 'Tab: '.$record->tab_name),
                TextColumn::make('target_table')->label('Table')->fontFamily('mono'),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (SyncStatus $state) => Str::headline($state->value))
                    ->color(fn (SyncStatus $state) => $state->color()),
                TextColumn::make('rows_read')->label('Read')->numeric(),
                TextColumn::make('rows_written')->label('Written')->numeric(),
                TextColumn::make('messages')
                    ->state(fn ($record) => array_filter([$record->error, ...($record->warnings ?? [])]))
                    ->listWithLineBreaks()
                    ->bulleted()
                    ->limitList(3)
                    ->expandableLimitedList()
                    ->color(fn ($record) => $record->error ? 'danger' : 'gray')
                    ->wrap(),
            ]);
    }
}
