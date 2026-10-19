<?php

namespace App\Filament\Resources\TableMappings\Pages;

use App\Filament\Resources\TableMappings\TableMappingResource;
use Filament\Resources\Pages\CreateRecord;

class CreateTableMapping extends CreateRecord
{
    protected static string $resource = TableMappingResource::class;
}
