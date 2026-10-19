<?php

namespace App\Services\Sync;

use App\Enums\SyncMode;
use App\Models\TableMapping;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Writes prepared rows to the target table according to the sync mode.
 * Every mode is idempotent: running the sync twice gives the same data.
 */
class RowWriter
{
    public function __construct(private readonly int $chunkSize = 500) {}

    /**
     * @param  list<array<string, mixed>>  $rows  target column => value
     * @param  list<string>  $keyColumns  target columns forming the unique key (upsert)
     * @return int rows written
     */
    public function write(TableMapping $mapping, array $rows, array $keyColumns, CarbonInterface $now): int
    {
        $table = $mapping->target_table;
        $meta = [
            'source_mapping_id' => $mapping->id,
            'sync_date' => $now->toDateString(),
            'synced_at' => $now->toDateTimeString(),
        ];

        $rows = array_map(fn (array $row) => $row + $meta, $rows);

        return DB::transaction(function () use ($mapping, $table, $rows, $keyColumns, $meta) {
            return match ($mapping->sync_mode) {
                SyncMode::Upsert => $this->upsert($mapping, $table, $rows, $keyColumns),
                SyncMode::Replace => $this->replace($table, $rows, $mapping->id),
                SyncMode::Snapshot => $this->replace($table, $rows, $mapping->id, $meta['sync_date']),
            };
        });
    }

    public static function rowKey(int $mappingId, array $keyValues): string
    {
        return sha1($mappingId."\x1f".json_encode(array_values($keyValues)));
    }

    private function upsert(TableMapping $mapping, string $table, array $rows, array $keyColumns): int
    {
        $keyed = [];

        foreach ($rows as $row) {
            $key = self::rowKey($mapping->id, array_map(fn ($c) => $row[$c] ?? null, $keyColumns));
            $keyed[$key] = ['row_key' => $key] + $row; // duplicate keys in the sheet: last row wins
        }

        // Rows without a key were written while this mapping was in replace/snapshot
        // mode; after a switch to upsert they would never be updated, only duplicated.
        DB::table($table)->where('source_mapping_id', $mapping->id)->whereNull('row_key')->delete();

        if ($keyed === []) {
            return 0;
        }

        $update = array_values(array_diff(array_keys(reset($keyed)), ['row_key', 'source_mapping_id']));

        foreach (array_chunk(array_values($keyed), $this->chunkSize) as $chunk) {
            DB::table($table)->upsert($chunk, ['row_key'], $update);
        }

        return count($keyed);
    }

    private function replace(string $table, array $rows, int $mappingId, ?string $syncDate = null): int
    {
        // Only this mapping's rows: other spreadsheets may write into the same table.
        DB::table($table)
            ->where('source_mapping_id', $mappingId)
            ->when($syncDate, fn ($q) => $q->where('sync_date', $syncDate))
            ->delete();

        foreach (array_chunk($rows, $this->chunkSize) as $chunk) {
            DB::table($table)->insert($chunk);
        }

        return count($rows);
    }
}
