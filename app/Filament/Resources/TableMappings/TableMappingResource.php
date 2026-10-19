<?php

namespace App\Filament\Resources\TableMappings;

use App\Enums\DataType;
use App\Enums\SyncMode;
use App\Filament\Resources\TableMappings\Pages\CreateTableMapping;
use App\Filament\Resources\TableMappings\Pages\EditTableMapping;
use App\Filament\Resources\TableMappings\Pages\ListTableMappings;
use App\Filament\Support\SheetLookup;
use App\Models\SheetTab;
use App\Models\TableMapping;
use App\Services\Sync\SchemaManager;
use App\Support\Identifier;
use BackedEnum;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ReplicateAction;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Schema as DbSchema;
use Illuminate\Support\Str;
use Throwable;
use UnitEnum;

class TableMappingResource extends Resource
{
    protected static ?string $model = TableMapping::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowsRightLeft;

    protected static string|UnitEnum|null $navigationGroup = 'Configuration';

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'target_table';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make('Source and target')
                    ->description('Rows of the selected tab are written to the target table. The table is created automatically if it does not exist.')
                    ->columns(2)
                    ->schema([
                        Select::make('sheet_tab_id')
                            ->label('Spreadsheet › Tab')
                            ->options(fn () => SheetTab::with('spreadsheet')->get()
                                ->groupBy(fn (SheetTab $t) => $t->spreadsheet->name)
                                ->map(fn ($tabs) => $tabs->pluck('tab_name', 'id'))
                                ->all())
                            ->default(fn () => request()->integer('sheet_tab_id') ?: null)
                            ->searchable()
                            ->required()
                            ->live(),
                        TextInput::make('target_table')
                            ->label('Target table')
                            ->required()
                            ->maxLength(64)
                            ->datalist(fn () => self::managedTables())
                            ->rule(fn (): Closure => self::targetTableRule()),
                        Select::make('sync_mode')
                            ->options(SyncMode::options())
                            ->default(SyncMode::Upsert->value)
                            ->required()
                            ->live()
                            ->helperText(fn (Get $get) => match ($get('sync_mode')) {
                                'upsert' => 'Inserts new rows and updates existing ones, matched by the unique key columns.',
                                'replace' => 'Deletes the rows this mapping wrote before, then inserts the current sheet.',
                                'snapshot' => 'Keeps one copy of the sheet per day. Re-running on the same day overwrites that day.',
                                default => null,
                            }),
                        Toggle::make('is_active')
                            ->label('Active')
                            ->default(true)
                            ->inline(false),
                    ]),

                Section::make('Columns')
                    ->description('Sheet columns are matched by header name, so their position in the sheet does not matter. New target columns are added to the table on the next sync.')
                    ->afterHeader([
                        Action::make('loadHeaders')
                            ->label('Load columns from sheet')
                            ->icon(Heroicon::OutlinedArrowDownTray)
                            ->link()
                            ->disabled(fn (Get $get) => blank($get('sheet_tab_id')))
                            ->action(fn (Get $get, Set $set) => self::loadHeaders($get, $set)),
                    ])
                    ->schema([
                        Repeater::make('columnMappings')
                            ->hiddenLabel()
                            ->relationship()
                            ->table([
                                TableColumn::make('Sheet column')->markAsRequired(),
                                TableColumn::make('Table column')->markAsRequired(),
                                TableColumn::make('Type'),
                                TableColumn::make('Unique key'),
                                TableColumn::make('Date format'),
                            ])
                            ->schema([
                                TextInput::make('source_column')
                                    ->required()
                                    ->maxLength(255)
                                    ->datalist(fn (Get $get) => SheetLookup::safeHeaders($get('../../sheet_tab_id')))
                                    ->live(onBlur: true)
                                    ->afterStateUpdated(function (?string $state, Get $get, Set $set) {
                                        if ($state && blank($get('target_column'))) {
                                            $set('target_column', Identifier::suggest($state));
                                        }
                                    }),
                                TextInput::make('target_column')
                                    ->required()
                                    ->maxLength(64)
                                    ->distinct()
                                    ->rule(fn (): Closure => self::targetColumnRule()),
                                Select::make('data_type')
                                    ->options(DataType::options())
                                    ->default(DataType::String->value)
                                    ->selectablePlaceholder(false)
                                    ->required()
                                    ->live(),
                                Toggle::make('is_unique_key')
                                    ->default(false),
                                TextInput::make('date_format')
                                    ->placeholder(fn (Get $get) => in_array($get('data_type'), ['date', 'datetime']) ? 'auto, e.g. d/m/Y' : '—')
                                    ->disabled(fn (Get $get) => ! in_array($get('data_type'), ['date', 'datetime']))
                                    ->maxLength(32),
                            ])
                            ->defaultItems(1)
                            ->addActionLabel('Add column')
                            ->reorderable(false)
                            ->minItems(1)
                            ->rule(fn (Get $get): Closure => function (string $attribute, $value, Closure $fail) use ($get) {
                                $hasKey = collect($value)->contains(fn ($item) => ! empty($item['is_unique_key']));

                                if ($get('sync_mode') === SyncMode::Upsert->value && ! $hasKey) {
                                    $fail('Upsert mode needs at least one column marked as unique key.');
                                }
                            }),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('target_table')
            ->modifyQueryUsing(fn ($query) => $query->with('sheetTab.spreadsheet'))
            ->columns([
                TextColumn::make('sheetTab.spreadsheet.name')
                    ->label('Spreadsheet')
                    ->description(fn (TableMapping $record) => 'Tab: '.$record->sheetTab->tab_name)
                    ->searchable()
                    ->sortable(),
                TextColumn::make('target_table')
                    ->label('Target table')
                    ->icon(Heroicon::OutlinedCircleStack)
                    ->fontFamily('mono')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('sync_mode')
                    ->badge()
                    ->formatStateUsing(fn (SyncMode $state) => Str::headline($state->value))
                    ->color(fn (SyncMode $state) => match ($state) {
                        SyncMode::Upsert => 'primary',
                        SyncMode::Replace => 'warning',
                        SyncMode::Snapshot => 'info',
                    }),
                TextColumn::make('columnMappings.target_column')
                    ->label('Columns')
                    ->badge()
                    ->color('gray')
                    ->limitList(4)
                    ->expandableLimitedList(),
                ToggleColumn::make('is_active')
                    ->label('Active'),
            ])
            ->filters([
                SelectFilter::make('sync_mode')->options(SyncMode::options()),
                SelectFilter::make('spreadsheet')
                    ->relationship('sheetTab.spreadsheet', 'name'),
            ])
            ->recordActions([
                EditAction::make(),
                ReplicateAction::make()
                    ->excludeAttributes(['column_mappings_count'])
                    ->after(function (TableMapping $replica, TableMapping $record) {
                        foreach ($record->columnMappings as $column) {
                            $replica->columnMappings()->create($column->only(['source_column', 'target_column', 'data_type', 'is_unique_key', 'date_format']));
                        }
                    }),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    /** @return list<string> */
    public static function managedTables(): array
    {
        $schema = app(SchemaManager::class);

        return TableMapping::query()->distinct()->orderBy('target_table')->pluck('target_table')
            ->filter(fn (string $table) => $schema->isManaged($table))
            ->values()
            ->all();
    }

    private static function targetTableRule(): Closure
    {
        return function (string $attribute, $value, Closure $fail) {
            if (! Identifier::isValid((string) $value)) {
                $fail('Use lowercase letters, digits and underscores only (max 64 characters).');
            } elseif (Identifier::isReservedTable($value)) {
                $fail("`{$value}` is an application table and cannot be used as a target.");
            } elseif (DbSchema::hasTable($value) && ! app(SchemaManager::class)->isManaged($value)) {
                $fail("`{$value}` already exists and was not created by the sync. Choose another name.");
            }
        };
    }

    private static function targetColumnRule(): Closure
    {
        return function (string $attribute, $value, Closure $fail) {
            if (! Identifier::isValid((string) $value)) {
                $fail('Use lowercase letters, digits and underscores only.');
            } elseif (Identifier::isReservedColumn($value)) {
                $fail("`{$value}` is reserved for sync metadata.");
            }
        };
    }

    private static function loadHeaders(Get $get, Set $set): void
    {
        try {
            $headers = SheetLookup::headers($get('sheet_tab_id'), fresh: true);
        } catch (Throwable $e) {
            Notification::make()->title('Could not read the sheet')->body($e->getMessage())->danger()->send();

            return;
        }

        $items = collect($get('columnMappings') ?? [])->filter(fn ($item) => filled($item['source_column'] ?? null));
        $known = $items->pluck('source_column')->map(fn ($s) => mb_strtolower(trim($s)))->all();
        $added = 0;

        foreach ($headers as $header) {
            if (in_array(mb_strtolower($header), $known, true)) {
                continue;
            }

            $items->put((string) Str::uuid(), [
                'source_column' => $header,
                'target_column' => Identifier::suggest($header),
                'data_type' => DataType::String->value,
                'is_unique_key' => false,
                'date_format' => null,
            ]);
            $added++;
        }

        $set('columnMappings', $items->all());

        Notification::make()
            ->title($added ? "Added {$added} column(s) from the sheet header" : 'All sheet columns are already mapped')
            ->body($added ? 'Review the types and pick the unique key, then save.' : null)
            ->success()
            ->send();
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTableMappings::route('/'),
            'create' => CreateTableMapping::route('/create'),
            'edit' => EditTableMapping::route('/{record}/edit'),
        ];
    }
}
