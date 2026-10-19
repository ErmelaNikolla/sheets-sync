<?php

namespace App\Services\Sync;

use App\Enums\DataType;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Converts raw cell values (UNFORMATTED_VALUE: numbers, booleans, strings,
 * date serial numbers) into values for the target column type.
 *
 * Returns [value, ok]. When ok is false the row is reported as a warning
 * instead of failing the whole sync: a NULL value could not be converted at
 * all, a non-NULL one was adjusted to fit (truncated text, rounded integer).
 */
class ValueCaster
{
    // Google Sheets / Excel day zero.
    private const SERIAL_EPOCH = '1899-12-30';

    /**
     * @return array{0: mixed, 1: bool}
     */
    public function cast(mixed $value, DataType $type, ?string $dateFormat = null): array
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return [null, true];
        }

        return match ($type) {
            DataType::String => $this->toString($value, 255),
            DataType::Text => $this->toString($value, 65535),
            DataType::Integer => $this->toNumber($value, integer: true),
            DataType::Decimal => $this->toNumber($value, integer: false),
            DataType::Date => $this->toDate($value, $dateFormat, withTime: false),
            DataType::DateTime => $this->toDate($value, $dateFormat, withTime: true),
            DataType::Boolean => $this->toBoolean($value),
        };
    }

    private function toString(mixed $value, int $max): array
    {
        if (is_bool($value)) {
            $value = $value ? 'TRUE' : 'FALSE';
        } elseif (is_float($value) && floor($value) === $value && abs($value) < PHP_INT_MAX) {
            $value = (string) (int) $value; // 12.0 → "12", not "12.0"
        }

        $value = trim((string) $value);

        if (mb_strlen($value) > $max) {
            return [mb_substr($value, 0, $max), false];
        }

        return [$value, true];
    }

    private function toNumber(mixed $value, bool $integer): array
    {
        if (is_bool($value)) {
            return [(int) $value, true];
        }

        if (is_string($value)) {
            // "1,234.50", "1.234,50", "€ 12", "15%" → numeric part.
            $percent = str_ends_with(trim($value), '%');
            $value = self::normalizeSeparators((string) preg_replace('/[^\d.,\-eE+]/', '', $value));

            if (! is_numeric($value)) {
                return [null, false];
            }

            $value = $percent ? $value / 100 : $value + 0;
        }

        if (! is_int($value) && ! is_float($value)) {
            return [null, false];
        }

        if ($integer) {
            $rounded = (int) round($value);

            // A fraction in an integer column is stored rounded, but reported.
            return [$rounded, (float) $rounded === (float) $value];
        }

        return [round((float) $value, 4), true];
    }

    /**
     * Turns thousands/decimal separators into plain "1234.5":
     *   both "," and "."  → the last one is the decimal separator ("1.234,50", "1,234.50")
     *   only ","          → thousands when grouped by 3 ("1,234", "1,234,567"), else decimal ("3,5")
     *   repeated "."      → thousands ("1.234.567"); a single "." stays decimal ("1.234")
     */
    public static function normalizeSeparators(string $value): string
    {
        $comma = strrpos($value, ',');
        $dot = strrpos($value, '.');

        if ($comma !== false && $dot !== false) {
            [$thousands, $decimal] = $comma > $dot ? ['.', ','] : [',', '.'];

            return str_replace([$thousands, $decimal], ['', '.'], $value);
        }

        if ($comma !== false) {
            if (preg_match('/^[-+]?\d{1,3}(,\d{3})+$/', $value)) {
                return str_replace(',', '', $value);
            }

            return substr_count($value, ',') === 1 ? str_replace(',', '.', $value) : $value;
        }

        if (preg_match('/^[-+]?\d{1,3}(\.\d{3}){2,}$/', $value)) {
            return str_replace('.', '', $value);
        }

        return $value;
    }

    private function toDate(mixed $value, ?string $format, bool $withTime): array
    {
        $out = $withTime ? 'Y-m-d H:i:s' : 'Y-m-d';

        try {
            if (is_int($value) || is_float($value)) {
                $days = (int) floor($value);
                $seconds = (int) round(($value - $days) * 86400);
                $date = CarbonImmutable::parse(self::SERIAL_EPOCH)->addDays($days)->addSeconds($seconds);

                return [$date->format($out), true];
            }

            $value = trim((string) $value);
            // "!" resets fields missing from the format, so "d/m/Y" gives 00:00:00, not the current time.
            $date = $format
                ? CarbonImmutable::createFromFormat(str_starts_with($format, '!') ? $format : '!'.$format, $value)
                : CarbonImmutable::parse($value);

            return $date ? [$date->format($out), true] : [null, false];
        } catch (Throwable) {
            return [null, false];
        }
    }

    private function toBoolean(mixed $value): array
    {
        if (is_bool($value)) {
            return [$value, true];
        }

        $normalized = mb_strtolower(trim((string) $value));

        return match (true) {
            in_array($normalized, ['1', 'true', 'yes', 'y', 'x', 'po'], true) => [true, true],
            in_array($normalized, ['0', 'false', 'no', 'n', 'jo'], true) => [false, true],
            default => [null, false],
        };
    }
}
