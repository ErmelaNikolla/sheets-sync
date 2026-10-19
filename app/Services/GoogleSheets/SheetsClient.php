<?php

namespace App\Services\GoogleSheets;

/**
 * The only boundary between the app and the spreadsheet provider.
 * Everything else works with plain PHP arrays, which keeps the sync
 * logic testable with the FakeSheetsClient.
 */
interface SheetsClient
{
    /**
     * @return list<string> Tab (sheet) titles in the order they appear.
     *
     * @throws SheetsException
     */
    public function getTabNames(string $spreadsheetId): array;

    /**
     * Read several tabs of one spreadsheet in a single request.
     *
     * @param  list<string>  $tabNames
     * @return array<string, list<list<mixed>>> Rows keyed by tab name. Tabs that do not exist are omitted.
     *
     * @throws SheetsException
     */
    public function getTabValues(string $spreadsheetId, array $tabNames): array;

    /**
     * The identity the spreadsheet must be shared with, if the driver has one.
     */
    public function identity(): ?string;
}
