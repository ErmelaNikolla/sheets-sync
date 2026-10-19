<?php

namespace App\Enums;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\ColumnDefinition;

enum DataType: string
{
    case String = 'string';
    case Text = 'text';
    case Integer = 'integer';
    case Decimal = 'decimal';
    case Date = 'date';
    case DateTime = 'datetime';
    case Boolean = 'boolean';

    public function addColumn(Blueprint $table, string $column): ColumnDefinition
    {
        return match ($this) {
            self::String => $table->string($column),
            self::Text => $table->text($column),
            self::Integer => $table->bigInteger($column),
            self::Decimal => $table->decimal($column, 18, 4),
            self::Date => $table->date($column),
            self::DateTime => $table->dateTime($column),
            self::Boolean => $table->boolean($column),
        };
    }

    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $t) => [$t->value => ucfirst($t->value)])->all();
    }
}
