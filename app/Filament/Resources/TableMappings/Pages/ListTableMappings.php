<?php

namespace App\Filament\Resources\TableMappings\Pages;

use App\Filament\Resources\TableMappings\TableMappingResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListTableMappings extends ListRecords
{
    protected static string $resource = TableMappingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
