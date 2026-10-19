<?php

namespace App\Services\GoogleSheets;

/**
 * Reads spreadsheets from JSON fixtures: {path}/{spreadsheetId}.json
 *
 *   { "tabs": { "Sales": [["date", "product"], [46000, "Pen"]] } }
 *
 * Used for tests and for demoing the project without Google credentials.
 */
class FakeSheetsClient implements SheetsClient
{
    /** @var array<string, array<string, list<list<mixed>>>> */
    private array $memory = [];

    public function __construct(private readonly ?string $path = null) {}

    /**
     * Register a spreadsheet in memory (tests).
     *
     * @param  array<string, list<list<mixed>>>  $tabs
     */
    public function put(string $spreadsheetId, array $tabs): static
    {
        $this->memory[$spreadsheetId] = $tabs;

        return $this;
    }

    public function getTabNames(string $spreadsheetId): array
    {
        return array_keys($this->load($spreadsheetId));
    }

    public function getTabValues(string $spreadsheetId, array $tabNames): array
    {
        return array_intersect_key($this->load($spreadsheetId), array_flip($tabNames));
    }

    public function identity(): ?string
    {
        return 'fake-driver@local';
    }

    private function load(string $spreadsheetId): array
    {
        if (isset($this->memory[$spreadsheetId])) {
            return $this->memory[$spreadsheetId];
        }

        $file = $this->path ? rtrim($this->path, '/\\').DIRECTORY_SEPARATOR.basename($spreadsheetId).'.json' : null;

        if (! $file || ! is_file($file)) {
            throw new SheetsException("Fake spreadsheet [{$spreadsheetId}] not found.");
        }

        $json = json_decode((string) file_get_contents($file), true);

        return $json['tabs'] ?? [];
    }
}
