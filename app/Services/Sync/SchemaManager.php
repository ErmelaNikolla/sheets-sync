<?php

namespace App\Services\Sync;

use App\Models\ColumnMapping;
use App\Models\TableMapping;
use App\Support\Identifier;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * Keeps target tables in line with the configuration: creates missing
 * tables and adds missing columns. It never drops or changes existing
 * columns, because that would destroy data.
 *
 * DDL causes an implicit commit in MySQL, so this must run before (and
 * outside of) the transaction that writes the rows.
 */
class SchemaManager
{
    /**
     * @return list<string> Human readable notes about schema changes made.
     */
    public function ensure(TableMapping $mapping): array
    {
        $table = $mapping->target_table;
        /** @var Collection<int, ColumnMapping> $columns */
        $columns = $mapping->columnMappings;

        Identifier::assertTable($table);
        $columns->each(fn (ColumnMapping $c) => Identifier::assertColumn($c->target_column));

        if (! Schema::hasTable($table)) {
            $this->create($table, $columns);

            return ["Created table `{$table}` with columns: ".$columns->pluck('target_column')->implode(', ')];
        }

        $this->assertManaged($table);

        $existing = array_map('strtolower', Schema::getColumnListing($table));
        $missing = $columns->reject(fn (ColumnMapping $c) => in_array($c->target_column, $existing, true));

        if ($missing->isEmpty()) {
            return [];
        }

        Schema::table($table, function (Blueprint $blueprint) use ($missing) {
            foreach ($missing as $column) {
                $column->data_type->addColumn($blueprint, $column->target_column)->nullable();
            }
        });

        return $missing->map(fn (ColumnMapping $c) => "Added column `{$c->target_column}` ({$c->data_type->value}) to `{$table}`")->values()->all();
    }

    public function isManaged(string $table): bool
    {
        return Schema::hasTable($table) && Schema::hasColumn($table, 'source_mapping_id');
    }

    /**
     * Only tables created by the sync (they carry `source_mapping_id`) may be
     * written to, so a mapping can never point at an unrelated existing table.
     */
    private function assertManaged(string $table): void
    {
        if (! $this->isManaged($table)) {
            throw new RuntimeException("Table `{$table}` already exists and is not managed by the sync (missing `source_mapping_id`). Pick another target table.");
        }
    }

    private function create(string $table, Collection $columns): void
    {
        Schema::create($table, function (Blueprint $blueprint) use ($columns) {
            $blueprint->id();
            $blueprint->unsignedBigInteger('source_mapping_id')->index();
            // sha1(mapping id + unique key values); one fixed unique index serves every
            // upsert mapping, whatever its key columns are. NULL for replace/snapshot rows.
            $blueprint->char('row_key', 40)->nullable()->unique();
            $blueprint->date('sync_date')->nullable()->index();

            foreach ($columns as $column) {
                $column->data_type->addColumn($blueprint, $column->target_column)->nullable();
            }

            $blueprint->timestamp('synced_at')->nullable();
        });
    }
}
