<?php

namespace App\Filament\Widgets;

use App\Enums\SyncStatus;
use App\Models\Spreadsheet;
use App\Models\SyncRun;
use App\Models\TableMapping;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Str;

class SyncOverview extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    protected ?string $pollingInterval = '10s';

    protected function getStats(): array
    {
        $last = SyncRun::latest('id')->first();

        $daily = collect(range(6, 0))->map(fn (int $daysAgo) => (int) SyncRun::whereDate('started_at', now()->subDays($daysAgo))->sum('rows_written'));

        return [
            Stat::make('Active spreadsheets', Spreadsheet::active()->count())
                ->description(Spreadsheet::count().' configured')
                ->icon(Heroicon::OutlinedTableCells),
            Stat::make('Table mappings', TableMapping::active()->count())
                ->description(TableMapping::distinct()->count('target_table').' target tables')
                ->icon(Heroicon::OutlinedArrowsRightLeft),
            Stat::make('Last sync', $last ? Str::headline($last->status->value) : 'Never')
                ->description($last ? $last->started_at?->diffForHumans().' · '.$last->trigger : 'Run "Sync now" or wait for the schedule')
                ->color($last?->status->color() ?? 'gray')
                ->icon(match ($last?->status) {
                    SyncStatus::Success => Heroicon::OutlinedCheckCircle,
                    SyncStatus::Failed => Heroicon::OutlinedXCircle,
                    SyncStatus::Partial => Heroicon::OutlinedExclamationTriangle,
                    default => Heroicon::OutlinedClock,
                }),
            Stat::make('Rows written (7 days)', number_format($daily->sum()))
                ->description('Next run daily at '.config('sheets.schedule_at'))
                ->chart($daily->all())
                ->color('primary'),
        ];
    }
}
