<?php

namespace App\Filament\Resources\Spreadsheets;

use App\Filament\Resources\Spreadsheets\Pages\CreateSpreadsheet;
use App\Filament\Resources\Spreadsheets\Pages\EditSpreadsheet;
use App\Filament\Resources\Spreadsheets\Pages\ListSpreadsheets;
use App\Filament\Resources\Spreadsheets\RelationManagers\TabsRelationManager;
use App\Filament\Support\SheetLookup;
use App\Filament\Support\SyncNowAction;
use App\Models\Spreadsheet;
use App\Services\GoogleSheets\SheetsClient;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;
use Throwable;
use UnitEnum;

class SpreadsheetResource extends Resource
{
    protected static ?string $model = Spreadsheet::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTableCells;

    protected static string|UnitEnum|null $navigationGroup = 'Configuration';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Google spreadsheet')
                    ->description(fn () => ($email = SheetLookup::identity())
                        ? "Share the spreadsheet with {$email} (Viewer access is enough)."
                        : 'Google credentials are not configured yet. See README › Google API credentials.')
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema([
                        TextInput::make('name')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('google_spreadsheet_id')
                            ->label('Spreadsheet URL or ID')
                            ->helperText('Paste the full docs.google.com link, the ID is extracted automatically.')
                            ->required()
                            ->maxLength(255)
                            ->dehydrateStateUsing(fn (string $state) => Spreadsheet::extractId($state))
                            ->unique(ignoreRecord: true),
                        Toggle::make('is_active')
                            ->label('Include in daily sync')
                            ->default(true),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->description(fn (Spreadsheet $record) => $record->google_spreadsheet_id),
                TextColumn::make('tabs_count')
                    ->counts('tabs')
                    ->label('Tabs')
                    ->badge(),
                TextColumn::make('table_mappings_count')
                    ->counts('tableMappings')
                    ->label('Mappings')
                    ->badge(),
                TextColumn::make('last_synced_at')
                    ->since()
                    ->placeholder('Never')
                    ->sortable(),
                ToggleColumn::make('is_active')
                    ->label('Active'),
            ])
            ->recordActions([
                SyncNowAction::make(spreadsheetIds: fn (Spreadsheet $record) => [$record->id])->iconButton()->tooltip('Sync now'),
                ActionGroup::make([
                    self::testConnectionAction(),
                    Action::make('open')
                        ->label('Open in Google Sheets')
                        ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                        ->url(fn (Spreadsheet $record) => $record->url(), shouldOpenInNewTab: true),
                    EditAction::make(),
                    DeleteAction::make(),
                ]),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function testConnectionAction(): Action
    {
        return Action::make('testConnection')
            ->label('Test connection')
            ->icon(Heroicon::OutlinedSignal)
            ->action(function (Spreadsheet $record) {
                try {
                    $tabs = app(SheetsClient::class)->getTabNames($record->google_spreadsheet_id);

                    Notification::make()
                        ->title('Connected')
                        ->body('Tabs found: '.implode(', ', $tabs))
                        ->success()
                        ->send();
                } catch (Throwable $e) {
                    Notification::make()
                        ->title('Connection failed')
                        ->body($e->getMessage())
                        ->danger()
                        ->persistent()
                        ->send();
                }
            });
    }

    public static function getRelations(): array
    {
        return [
            TabsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSpreadsheets::route('/'),
            'create' => CreateSpreadsheet::route('/create'),
            'edit' => EditSpreadsheet::route('/{record}/edit'),
        ];
    }
}
