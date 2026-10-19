<?php

namespace App\Filament\Pages;

use App\Filament\Resources\TableMappings\TableMappingResource;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Attributes\Url;
use UnitEnum;

/**
 * Read-only view of the tables the sync writes to, to check what landed.
 */
class DataBrowser extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament.pages.data-browser';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCircleStack;

    protected static string|UnitEnum|null $navigationGroup = 'Monitoring';

    protected static ?int $navigationSort = 2;

    protected static ?string $title = 'Synced data';

    #[Url]
    public ?string $source = null;

    public function mount(): void
    {
        if (! in_array($this->source, $this->getTables(), true)) {
            $this->source = $this->getTables()[0] ?? null;
        }
    }

    public function updatedSource(): void
    {
        $this->resetTable();
    }

    /** @return list<string> */
    public function getTables(): array
    {
        return TableMappingResource::managedTables();
    }

    public function table(Table $table): Table
    {
        $source = in_array($this->source, $this->getTables(), true) ? $this->source : null;
        $columns = $source ? Schema::getColumnListing($source) : [];
        // Data columns first, sync metadata last.
        $meta = ['source_mapping_id', 'row_key', 'sync_date', 'synced_at'];
        $columns = [...array_diff($columns, $meta), ...array_intersect($meta, $columns)];

        return $table
            ->records(function (int $page, int $recordsPerPage, ?string $sortColumn, ?string $sortDirection, ?string $search) use ($source, $columns) {
                if (! $source) {
                    return new LengthAwarePaginator([], 0, $recordsPerPage, $page);
                }

                $query = DB::table($source);

                if (in_array($sortColumn, $columns, true)) {
                    $query->orderBy($sortColumn, $sortDirection === 'desc' ? 'desc' : 'asc');
                } else {
                    $query->orderByDesc('id');
                }

                if (filled($search)) {
                    $query->where(function ($q) use ($columns, $search) {
                        foreach ($columns as $column) {
                            $q->orWhere($column, 'like', '%'.$search.'%');
                        }
                    });
                }

                $paginator = $query->paginate($recordsPerPage, ['*'], 'page', $page);

                return $paginator->setCollection($paginator->getCollection()->mapWithKeys(fn ($row) => [$row->id => (array) $row]));
            })
            ->columns(array_map(fn (string $column) => TextColumn::make($column)
                ->sortable()
                ->placeholder('NULL')
                ->fontFamily(in_array($column, ['row_key'], true) ? 'mono' : null)
                ->limit(40)
                ->toggleable(isToggledHiddenByDefault: in_array($column, ['row_key', 'source_mapping_id'], true)), $columns))
            ->searchable()
            ->emptyStateHeading($source ? 'No rows yet' : 'No synced tables yet')
            ->emptyStateDescription($source ? 'Run a sync to fill this table.' : 'Create a table mapping and run a sync.');
    }
}
