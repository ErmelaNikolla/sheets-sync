<?php

namespace App\Jobs;

use App\Services\Sync\SheetSyncService;
use App\Services\Sync\SyncAlreadyRunning;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Used by the UI "Sync now" buttons so the HTTP request returns immediately.
 */
class RunSheetsSync implements ShouldQueue
{
    use Queueable;

    public int $timeout = 3600;

    public int $tries = 1;

    /**
     * @param  list<int>|null  $spreadsheetIds
     */
    public function __construct(public readonly ?array $spreadsheetIds = null) {}

    public function handle(SheetSyncService $sync): void
    {
        try {
            $sync->run('manual', $this->spreadsheetIds);
        } catch (SyncAlreadyRunning) {
            // A run is in progress; it will pick up the same configuration.
        }
    }
}
