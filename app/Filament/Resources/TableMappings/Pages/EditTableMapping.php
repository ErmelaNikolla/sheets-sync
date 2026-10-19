<?php

namespace App\Filament\Resources\TableMappings\Pages;

use App\Filament\Resources\TableMappings\TableMappingResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditTableMapping extends EditRecord
{
    protected static string $resource = TableMappingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
