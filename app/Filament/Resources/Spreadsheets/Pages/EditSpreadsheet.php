<?php

namespace App\Filament\Resources\Spreadsheets\Pages;

use App\Filament\Resources\Spreadsheets\SpreadsheetResource;
use App\Filament\Support\SyncNowAction;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditSpreadsheet extends EditRecord
{
    protected static string $resource = SpreadsheetResource::class;

    protected function getHeaderActions(): array
    {
        return [
            SpreadsheetResource::testConnectionAction()->color('gray'),
            SyncNowAction::make(spreadsheetIds: fn () => [$this->record->id]),
            DeleteAction::make(),
        ];
    }
}
