<?php

namespace App\Console\Commands;

use App\Enums\SyncStatus;
use App\Services\Sync\SheetSyncService;
use App\Services\Sync\SyncAlreadyRunning;
use Illuminate\Console\Command;

class SyncSheetsCommand extends Command
{
    protected $signature = 'sheets:sync
        {--spreadsheet=* : Only sync these spreadsheet IDs (database IDs)}
        {--trigger=cli : Recorded on the sync run (cli, schedule, manual)}';

    protected $description = 'Read the configured Google Sheets and store their rows in the mapped tables';

    public function handle(SheetSyncService $sync): int
    {
        $ids = array_map('intval', $this->option('spreadsheet')) ?: null;

        try {
            $run = $sync->run($this->option('trigger'), $ids);
        } catch (SyncAlreadyRunning $e) {
            $this->warn($e->getMessage());

            return self::SUCCESS;
        }

        $this->table(
            ['Spreadsheet', 'Tab', 'Table', 'Status', 'Read', 'Written', 'Message'],
            $run->items->map(fn ($item) => [
                $item->spreadsheet_name,
                $item->tab_name,
                $item->target_table,
                $item->status->value,
                $item->rows_read,
                $item->rows_written,
                $item->error ?? implode("\n", $item->warnings ?? []),
            ]),
        );

        $this->line("Run #{$run->id}: <options=bold>{$run->status->value}</>, {$run->rows_written} rows written in {$run->durationForHumans()}.");

        if ($run->error) {
            $this->error($run->error);
        }

        return $run->status === SyncStatus::Failed ? self::FAILURE : self::SUCCESS;
    }
}
