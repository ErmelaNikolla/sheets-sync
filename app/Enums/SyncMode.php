<?php

namespace App\Enums;

enum SyncMode: string
{
    /** Insert new rows and update existing ones, matched by the unique key columns. */
    case Upsert = 'upsert';

    /** Delete everything this mapping wrote before, then insert the current sheet. */
    case Replace = 'replace';

    /** Keep one copy of the sheet per day (re-running on the same day overwrites that day). */
    case Snapshot = 'snapshot';

    public function label(): string
    {
        return match ($this) {
            self::Upsert => 'Upsert (by unique key)',
            self::Replace => 'Replace (full refresh)',
            self::Snapshot => 'Daily snapshot',
        };
    }

    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $m) => [$m->value => $m->label()])->all();
    }
}
