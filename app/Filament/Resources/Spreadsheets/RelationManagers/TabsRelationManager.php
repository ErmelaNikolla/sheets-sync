<?php

namespace App\Filament\Resources\Spreadsheets\RelationManagers;

use App\Filament\Resources\TableMappings\TableMappingResource;
use App\Filament\Support\SheetLookup;
use App\Models\SheetTab;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;
use Illuminate\Validation\Rules\Unique;

class TabsRelationManager extends RelationManager
{
    protected static string $relationship = 'tabs';

    protected static ?string $title = 'Tabs to extract';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('tab_name')
                    ->label('Tab name')
                    ->required()
                    ->maxLength(255)
                    ->datalist(fn () => SheetLookup::tabNames($this->getOwnerRecord()->google_spreadsheet_id))
                    ->helperText('Suggestions are read from the live spreadsheet.')
                    ->unique(ignoreRecord: true, modifyRuleUsing: fn (Unique $rule) => $rule->where('spreadsheet_id', $this->getOwnerRecord()->id)),
                TextInput::make('header_row')
                    ->label('Header row')
                    ->helperText('Row number holding the column names. Data starts below it.')
                    ->numeric()
                    ->minValue(1)
                    ->default(1)
                    ->required(),
                Toggle::make('is_active')
                    ->default(true),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('tab_name')
            ->columns([
                TextColumn::make('tab_name')
                    ->searchable(),
                TextColumn::make('header_row')
                    ->label('Header row'),
                TextColumn::make('tableMappings.target_table')
                    ->label('Target tables')
                    ->badge()
                    ->placeholder('No mapping yet'),
                ToggleColumn::make('is_active')
                    ->label('Active'),
            ])
            ->headerActions([
                CreateAction::make()->label('Add tab'),
            ])
            ->recordActions([
                Action::make('addMapping')
                    ->label('Map to table')
                    ->icon(Heroicon::OutlinedArrowsRightLeft)
                    ->url(fn (SheetTab $record) => TableMappingResource::getUrl('create', ['sheet_tab_id' => $record->id])),
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
