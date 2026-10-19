<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Table and column names come from user configuration and end up in DDL
 * (CREATE / ALTER TABLE), where bindings cannot be used. They are therefore
 * whitelisted: lowercase letters, digits and underscores, max 64 chars
 * (the MySQL identifier limit).
 */
final class Identifier
{
    public const PATTERN = '/^[a-z_][a-z0-9_]{0,63}$/';

    /** Columns the sync adds to every managed table. */
    public const SYSTEM_COLUMNS = ['id', 'source_mapping_id', 'row_key', 'sync_date', 'synced_at'];

    public static function isValid(string $name): bool
    {
        return (bool) preg_match(self::PATTERN, $name);
    }

    public static function isReservedTable(string $name): bool
    {
        return in_array($name, config('sheets.reserved_tables', []), true);
    }

    public static function isReservedColumn(string $name): bool
    {
        return in_array($name, self::SYSTEM_COLUMNS, true);
    }

    public static function assertTable(string $name): void
    {
        if (! self::isValid($name)) {
            throw new InvalidArgumentException("Invalid table name [{$name}]: use lowercase letters, digits and underscores (max 64).");
        }

        if (self::isReservedTable($name)) {
            throw new InvalidArgumentException("Table [{$name}] is reserved by the application and cannot be a sync target.");
        }
    }

    public static function assertColumn(string $name): void
    {
        if (! self::isValid($name)) {
            throw new InvalidArgumentException("Invalid column name [{$name}]: use lowercase letters, digits and underscores (max 64).");
        }

        if (self::isReservedColumn($name)) {
            throw new InvalidArgumentException("Column [{$name}] is reserved for sync metadata.");
        }
    }

    /**
     * Suggest a valid identifier from a free-form header, e.g. "Product Name" → "product_name".
     */
    public static function suggest(string $header): string
    {
        $name = strtolower(trim((string) preg_replace('/[^A-Za-z0-9]+/', '_', $header), '_'));

        if ($name === '' || ctype_digit($name[0])) {
            $name = 'col_'.$name;
        }

        $name = substr($name, 0, 64);

        return self::isReservedColumn($name) ? 'sheet_'.$name : $name;
    }
}
