<?php

namespace App\Filament\Widgets;

use App\Enums\SyncStatus;
use App\Filament\Resources\SyncRuns\SyncRunResource;
use App\Models\SyncRunItem;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Support\Str;

class LatestSyncRuns extends TableWidget
{
    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $heading = 'Latest activity';

    public function table(Table $table): Table
    {
        return $table
            ->query(SyncRunItem::query()->latest('id')->limit(10))
            ->paginated(false)
            ->poll('10s')
            ->columns([
                TextColumn::make('created_at')->since()->label('When'),
                TextColumn::make('spreadsheet_name')->label('Spreadsheet')->description(fn ($record) => 'Tab: '.$record->tab_name),
                TextColumn::make('target_table')->label('Table')->fontFamily('mono'),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (SyncStatus $state) => Str::headline($state->value))
                    ->color(fn (SyncStatus $state) => $state->color()),
                TextColumn::make('rows_written')->label('Rows')->numeric(),
                TextColumn::make('error')->limit(70)->color('danger')->placeholder('—'),
            ])
            ->recordUrl(fn (SyncRunItem $record) => SyncRunResource::getUrl('view', ['record' => $record->sync_run_id]));
    }
}
