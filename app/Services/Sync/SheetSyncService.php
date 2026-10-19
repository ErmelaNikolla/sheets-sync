<?php

namespace App\Services\Sync;

use App\Enums\SyncMode;
use App\Enums\SyncStatus;
use App\Models\ColumnMapping;
use App\Models\SheetTab;
use App\Models\Spreadsheet;
use App\Models\SyncRun;
use App\Models\SyncRunItem;
use App\Models\TableMapping;
use App\Services\GoogleSheets\SheetsClient;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Orchestrates one sync run:
 *   spreadsheet → one batchGet for all its tabs → each table mapping
 *   → match headers → ensure schema → cast values → write rows.
 *
 * Each table mapping is isolated: a failure is recorded on its
 * sync_run_item and the run carries on with the others.
 */
class SheetSyncService
{
    private const LOCK = 'sheets-sync:run';

    private const MAX_WARNINGS = 20;

    public function __construct(
        private readonly SheetsClient $client,
        private readonly HeaderMatcher $matcher,
        private readonly ValueCaster $caster,
        private readonly SchemaManager $schema,
        private readonly RowWriter $writer,
    ) {}

    /**
     * @param  list<int>|null  $spreadsheetIds  limit the run to these spreadsheets (null = all active)
     */
    public function run(string $trigger, ?array $spreadsheetIds = null): SyncRun
    {
        $lock = Cache::lock(self::LOCK, 3600);

        if (! $lock->get()) {
            throw new SyncAlreadyRunning('Another sync is already running.');
        }

        $run = null;

        try {
            $run = SyncRun::create([
                'trigger' => $trigger,
                'status' => SyncStatus::Running,
                'started_at' => now(),
            ]);

            $spreadsheets = Spreadsheet::active()
                ->when($spreadsheetIds, fn ($q) => $q->whereKey($spreadsheetIds))
                ->with(['tabs' => fn ($q) => $q->active()->with([
                    'tableMappings' => fn ($q) => $q->active()->with('columnMappings'),
                ])])
                ->get();

            foreach ($spreadsheets as $spreadsheet) {
                $this->syncSpreadsheet($run, $spreadsheet);
            }

            $this->finish($run);
        } catch (Throwable $e) {
            if (! $run) {
                throw $e; // could not even record the run; the lock is still released below
            }

            Log::error('Sheets sync crashed', ['run' => $run->id, 'exception' => $e]);
            $run->update(['status' => SyncStatus::Failed, 'error' => $e->getMessage(), 'finished_at' => now()]);
        } finally {
            $lock->release();
        }

        return $run->refresh();
    }

    /**
     * Header row of a tab, used by the UI to offer source columns.
     *
     * @return list<string>
     */
    public function headers(SheetTab $tab): array
    {
        $values = $this->client->getTabValues($tab->spreadsheet->google_spreadsheet_id, [$tab->tab_name]);

        if (! array_key_exists($tab->tab_name, $values)) {
            throw new RuntimeException("Tab \"{$tab->tab_name}\" not found in the spreadsheet.");
        }

        $header = $values[$tab->tab_name][$tab->header_row - 1] ?? [];

        return array_values(array_filter(array_map(fn ($h) => trim((string) $h), $header), fn ($h) => $h !== ''));
    }

    private function syncSpreadsheet(SyncRun $run, Spreadsheet $spreadsheet): void
    {
        $tabs = $spreadsheet->tabs->filter(fn (SheetTab $t) => $t->tableMappings->isNotEmpty());

        if ($tabs->isEmpty()) {
            return;
        }

        try {
            $values = $this->client->getTabValues(
                $spreadsheet->google_spreadsheet_id,
                $tabs->pluck('tab_name')->all(),
            );
        } catch (Throwable $e) {
            // The whole spreadsheet is unreadable: mark every mapping of it as failed.
            foreach ($tabs as $tab) {
                foreach ($tab->tableMappings as $mapping) {
                    $this->newItem($run, $spreadsheet, $tab, $mapping)->update([
                        'status' => SyncStatus::Failed,
                        'error' => $e->getMessage(),
                        'finished_at' => now(),
                    ]);
                }
            }

            return;
        }

        $succeeded = false;

        foreach ($tabs as $tab) {
            foreach ($tab->tableMappings as $mapping) {
                $item = $this->newItem($run, $spreadsheet, $tab, $mapping);
                $succeeded = $this->syncMapping($item, $tab, $mapping, $values) || $succeeded;
            }
        }

        if ($succeeded) {
            $spreadsheet->update(['last_synced_at' => now()]);
        }
    }

    private function syncMapping(SyncRunItem $item, SheetTab $tab, TableMapping $mapping, array $values): bool
    {
        $warnings = [];

        try {
            $columns = $mapping->columnMappings;

            if ($columns->isEmpty()) {
                throw new RuntimeException('Mapping has no columns configured.');
            }

            if (! array_key_exists($tab->tab_name, $values)) {
                throw new RuntimeException("Tab \"{$tab->tab_name}\" not found in the spreadsheet.");
            }

            $rows = $values[$tab->tab_name];
            $headerIndex = max(0, $tab->header_row - 1);

            if (! isset($rows[$headerIndex])) {
                throw new RuntimeException("Header row {$tab->header_row} is empty.");
            }

            $match = $this->matcher->match($rows[$headerIndex], $columns->pluck('source_column')->all());
            $keyColumns = $columns->where('is_unique_key', true);

            foreach ($match['duplicates'] as $header) {
                $warnings[] = "Header \"{$header}\" appears more than once; the first one is used.";
            }

            foreach ($match['missing'] as $source) {
                if ($keyColumns->contains('source_column', $source)) {
                    throw new RuntimeException("Unique key column \"{$source}\" was not found in the sheet header.");
                }
                // Left out of the row entirely, so an upsert keeps the value already stored.
                $warnings[] = "Column \"{$source}\" was not found in the sheet header; it was skipped.";
            }

            if ($mapping->sync_mode === SyncMode::Upsert && $keyColumns->isEmpty()) {
                throw new RuntimeException('Upsert mode needs at least one column marked as unique key.');
            }

            // DDL first, outside any transaction (implicit commit in MySQL).
            $warnings = [...$this->schema->ensure($mapping), ...$warnings];

            $present = $columns->filter(fn (ColumnMapping $c) => isset($match['indexes'][$c->source_column]));
            $records = [];
            $read = 0;
            $invalid = 0;
            $adjusted = 0;
            $skipped = 0;

            foreach (array_slice($rows, $headerIndex + 1) as $offset => $row) {
                if ($this->isEmptyRow($row)) {
                    continue;
                }

                $read++;
                $sheetRow = $tab->header_row + 1 + $offset;
                $record = [];

                foreach ($present as $column) {
                    $raw = $row[$match['indexes'][$column->source_column]] ?? null; // short rows: trailing empty cells are omitted by the API
                    [$value, $ok] = $this->caster->cast($raw, $column->data_type, $column->date_format);

                    if (! $ok && $value === null) {
                        $invalid++;
                        $this->warn($warnings, "Row {$sheetRow}: \"{$raw}\" is not a valid {$column->data_type->value} for `{$column->target_column}`.");
                    } elseif (! $ok) {
                        $adjusted++;
                        $this->warn($warnings, "Row {$sheetRow}: value for `{$column->target_column}` was adjusted to fit {$column->data_type->value} (\"".Str::limit((string) $raw, 40)."\" → \"".Str::limit((string) $value, 40)."\").");
                    }

                    $record[$column->target_column] = $value;
                }

                if ($mapping->sync_mode === SyncMode::Upsert
                    && $keyColumns->every(fn (ColumnMapping $c) => $record[$c->target_column] === null)) {
                    $skipped++;

                    continue;
                }

                $records[] = $record;
            }

            if ($invalid > 0) {
                $warnings[] = "{$invalid} value(s) could not be converted and were stored as NULL.";
            }

            if ($adjusted > 0) {
                $warnings[] = "{$adjusted} value(s) were truncated or rounded to fit the column type.";
            }

            if ($skipped > 0) {
                $warnings[] = "{$skipped} row(s) skipped because the unique key was empty.";
            }

            $written = $this->writer->write($mapping, $records, $keyColumns->pluck('target_column')->all(), CarbonImmutable::now());

            $item->update([
                'status' => SyncStatus::Success,
                'rows_read' => $read,
                'rows_written' => $written,
                'warnings' => $warnings ?: null,
                'finished_at' => now(),
            ]);

            return true;
        } catch (Throwable $e) {
            Log::warning('Sheets sync mapping failed', ['mapping' => $mapping->id, 'error' => $e->getMessage()]);

            $item->update([
                'status' => SyncStatus::Failed,
                'warnings' => $warnings ?: null,
                'error' => $e->getMessage(),
                'finished_at' => now(),
            ]);

            return false;
        }
    }

    private function newItem(SyncRun $run, Spreadsheet $spreadsheet, SheetTab $tab, TableMapping $mapping): SyncRunItem
    {
        return $run->items()->create([
            'table_mapping_id' => $mapping->id,
            'spreadsheet_name' => $spreadsheet->name,
            'tab_name' => $tab->tab_name,
            'target_table' => $mapping->target_table,
            'status' => SyncStatus::Running,
            'started_at' => now(),
        ]);
    }

    private function finish(SyncRun $run): void
    {
        $items = $run->items()->get();
        $failed = $items->where('status', SyncStatus::Failed)->count();

        $status = match (true) {
            $items->isEmpty() || $failed === 0 => SyncStatus::Success,
            $failed === $items->count() => SyncStatus::Failed,
            default => SyncStatus::Partial,
        };

        $run->update([
            'status' => $status,
            'rows_read' => $items->sum('rows_read'),
            'rows_written' => $items->sum('rows_written'),
            'error' => $items->isEmpty() ? 'Nothing to sync: no active spreadsheet with active tabs and mappings.' : null,
            'finished_at' => now(),
        ]);
    }

    private function isEmptyRow(array $row): bool
    {
        foreach ($row as $cell) {
            if ($cell !== null && trim((string) $cell) !== '') {
                return false;
            }
        }

        return true;
    }

    private function warn(array &$warnings, string $message): void
    {
        if (count($warnings) < self::MAX_WARNINGS) {
            $warnings[] = $message;
        }
    }
}
