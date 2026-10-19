<?php

namespace App\Filament\Support;

use App\Models\SheetTab;
use App\Services\GoogleSheets\SheetsClient;
use App\Services\Sync\SheetSyncService;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Cached live lookups used to fill form suggestions (tab names, headers).
 * Failures return an empty list, so the forms still work with free text.
 */
class SheetLookup
{
    public static function tabNames(?string $spreadsheetId): array
    {
        if (! $spreadsheetId) {
            return [];
        }

        try {
            return Cache::remember("sheets:tabs:{$spreadsheetId}", 300, fn () => app(SheetsClient::class)->getTabNames($spreadsheetId));
        } catch (Throwable) {
            return [];
        }
    }

    public static function headers(?int $sheetTabId, bool $fresh = false): array
    {
        $tab = $sheetTabId ? SheetTab::with('spreadsheet')->find($sheetTabId) : null;

        if (! $tab) {
            return [];
        }

        $key = "sheets:headers:{$tab->id}:{$tab->tab_name}:{$tab->header_row}";

        if ($fresh) {
            Cache::forget($key);
        }

        return Cache::remember($key, 300, fn () => app(SheetSyncService::class)->headers($tab));
    }

    public static function safeHeaders(?int $sheetTabId): array
    {
        try {
            return self::headers($sheetTabId);
        } catch (Throwable) {
            return [];
        }
    }

    public static function identity(): ?string
    {
        return app(SheetsClient::class)->identity();
    }
}
