<?php

namespace App\Filament\Resources\SyncRuns;

use App\Enums\SyncStatus;
use App\Filament\Resources\SyncRuns\Pages\ListSyncRuns;
use App\Filament\Resources\SyncRuns\Pages\ViewSyncRun;
use App\Filament\Resources\SyncRuns\RelationManagers\ItemsRelationManager;
use App\Models\SyncRun;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Str;
use UnitEnum;

class SyncRunResource extends Resource
{
    protected static ?string $model = SyncRun::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    protected static string|UnitEnum|null $navigationGroup = 'Monitoring';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'id';

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getNavigationBadge(): ?string
    {
        $failed = SyncRun::where('status', SyncStatus::Failed)->where('created_at', '>=', now()->subDay())->count();

        return $failed ? (string) $failed : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make()
                    ->columns(4)
                    ->columnSpanFull()
                    ->schema([
                        TextEntry::make('status')
                            ->badge()
                            ->formatStateUsing(fn (SyncStatus $state) => Str::headline($state->value))
                            ->color(fn (SyncStatus $state) => $state->color()),
                        TextEntry::make('trigger')->badge()->color('gray'),
                        TextEntry::make('started_at')->dateTime(),
                        TextEntry::make('duration')->state(fn (SyncRun $record) => $record->durationForHumans() ?? '—'),
                        TextEntry::make('rows_read')->numeric(),
                        TextEntry::make('rows_written')->numeric(),
                        TextEntry::make('error')
                            ->color('danger')
                            ->columnSpanFull()
                            ->visible(fn (SyncRun $record) => filled($record->error)),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->poll('5s')
            ->columns([
                TextColumn::make('id')->label('#')->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (SyncStatus $state) => Str::headline($state->value))
                    ->color(fn (SyncStatus $state) => $state->color()),
                TextColumn::make('trigger')->badge()->color('gray'),
                TextColumn::make('started_at')
                    ->dateTime()
                    ->description(fn (SyncRun $record) => $record->started_at?->diffForHumans())
                    ->sortable(),
                TextColumn::make('duration')->state(fn (SyncRun $record) => $record->durationForHumans() ?? '—'),
                TextColumn::make('items_count')->counts('items')->label('Mappings'),
                TextColumn::make('rows_read')->numeric()->label('Read'),
                TextColumn::make('rows_written')->numeric()->label('Written'),
                TextColumn::make('error')->limit(60)->color('danger')->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')->options(collect(SyncStatus::cases())->mapWithKeys(fn ($s) => [$s->value => Str::headline($s->value)])),
                SelectFilter::make('trigger')->options(['schedule' => 'Schedule', 'manual' => 'Manual', 'cli' => 'CLI']),
            ])
            ->recordActions([
                ViewAction::make(),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            ItemsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSyncRuns::route('/'),
            'view' => ViewSyncRun::route('/{record}'),
        ];
    }
}
