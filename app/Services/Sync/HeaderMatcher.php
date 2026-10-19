<?php

namespace App\Services\Sync;

/**
 * Finds columns by header name, never by position, so sheets with
 * reordered or extra columns map the same way.
 */
class HeaderMatcher
{
    public static function normalize(mixed $header): string
    {
        $header = (string) $header;
        // Strip a UTF-8 BOM and non-breaking spaces that often sneak into exported headers.
        $header = str_replace(["\u{FEFF}", "\u{00A0}"], ['', ' '], $header);

        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $header)));
    }

    /**
     * @param  list<mixed>  $headerRow
     * @param  list<string>  $sourceColumns
     * @return array{indexes: array<string, int>, missing: list<string>, duplicates: list<string>}
     */
    public function match(array $headerRow, array $sourceColumns): array
    {
        $positions = [];
        $duplicates = [];

        foreach ($headerRow as $index => $header) {
            $key = self::normalize($header);

            if ($key === '') {
                continue;
            }

            if (isset($positions[$key])) {
                $duplicates[$key] = $key;

                continue; // first occurrence wins
            }

            $positions[$key] = $index;
        }

        $indexes = [];
        $missing = [];

        foreach ($sourceColumns as $source) {
            $key = self::normalize($source);

            if (isset($positions[$key])) {
                $indexes[$source] = $positions[$key];
            } else {
                $missing[] = $source;
            }
        }

        $wanted = array_map(self::normalize(...), $sourceColumns);

        return [
            'indexes' => $indexes,
            'missing' => $missing,
            'duplicates' => array_values(array_intersect($duplicates, $wanted)),
        ];
    }
}
