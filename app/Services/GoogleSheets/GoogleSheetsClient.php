<?php

namespace App\Services\GoogleSheets;

use Google\Client;
use Google\Service\Exception as GoogleServiceException;
use Google\Service\Sheets;
use Throwable;

class GoogleSheetsClient implements SheetsClient
{
    private ?Sheets $service = null;

    public function __construct(private readonly string $credentialsPath) {}

    public function getTabNames(string $spreadsheetId): array
    {
        $spreadsheet = $this->call(fn () => $this->service()->spreadsheets->get($spreadsheetId, [
            'fields' => 'sheets.properties.title',
        ]));

        return array_map(fn ($sheet) => $sheet->getProperties()->getTitle(), $spreadsheet->getSheets());
    }

    public function getTabValues(string $spreadsheetId, array $tabNames): array
    {
        // Ask only for tabs that exist, otherwise the whole batchGet fails on one bad name.
        $existing = array_values(array_intersect($tabNames, $this->getTabNames($spreadsheetId)));

        if ($existing === []) {
            return [];
        }

        $response = $this->call(fn () => $this->service()->spreadsheets_values->batchGet($spreadsheetId, [
            'ranges' => array_map(self::quoteTab(...), $existing),
            'majorDimension' => 'ROWS',
            // Raw numbers and date serials instead of locale-formatted strings.
            'valueRenderOption' => 'UNFORMATTED_VALUE',
            'dateTimeRenderOption' => 'SERIAL_NUMBER',
        ]));

        $result = [];

        // Value ranges come back in the order they were requested.
        foreach ($response->getValueRanges() as $i => $range) {
            $result[$existing[$i]] = $range->getValues() ?? [];
        }

        return $result;
    }

    public function identity(): ?string
    {
        if (! is_file($this->credentialsPath)) {
            return null;
        }

        $json = json_decode((string) file_get_contents($this->credentialsPath), true);

        return $json['client_email'] ?? null;
    }

    public static function quoteTab(string $tab): string
    {
        return "'".str_replace("'", "''", $tab)."'";
    }

    private function service(): Sheets
    {
        if ($this->service) {
            return $this->service;
        }

        if (! is_file($this->credentialsPath)) {
            throw new SheetsException("Google credentials file not found at [{$this->credentialsPath}]. See README › Google API credentials.");
        }

        $client = new Client([
            // Exponential backoff on 429 / 5xx responses.
            'retry' => ['retries' => 4],
        ]);
        $client->setApplicationName(config('app.name'));
        $client->setAuthConfig($this->credentialsPath);
        $client->setScopes([Sheets::SPREADSHEETS_READONLY]);

        return $this->service = new Sheets($client);
    }

    private function call(callable $request): mixed
    {
        try {
            return $request();
        } catch (GoogleServiceException $e) {
            $reason = $e->getErrors()[0]['message'] ?? $e->getMessage();

            $hint = match ($e->getCode()) {
                403 => ' Share the spreadsheet with '.($this->identity() ?? 'the service account').' (Viewer is enough).',
                404 => ' Check the spreadsheet ID.',
                429 => ' Google API quota exceeded, try again later.',
                default => '',
            };

            throw new SheetsException("Google Sheets API error {$e->getCode()}: {$reason}.{$hint}", $e->getCode(), $e);
        } catch (SheetsException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new SheetsException('Google Sheets request failed: '.$e->getMessage(), 0, $e);
        }
    }
}
