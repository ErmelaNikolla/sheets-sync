<?php

namespace App\Filament\Resources\SyncRuns\Pages;

use App\Filament\Resources\SyncRuns\SyncRunResource;
use App\Filament\Support\SyncNowAction;
use Filament\Resources\Pages\ListRecords;

class ListSyncRuns extends ListRecords
{
    protected static string $resource = SyncRunResource::class;

    protected function getHeaderActions(): array
    {
        return [
            SyncNowAction::make()->label('Sync all now'),
        ];
    }
}
