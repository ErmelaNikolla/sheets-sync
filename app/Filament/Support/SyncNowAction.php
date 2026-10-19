<?php

namespace App\Filament\Support;

use App\Enums\SyncStatus;
use App\Filament\Resources\SyncRuns\SyncRunResource;
use App\Jobs\RunSheetsSync;
use App\Models\SyncRun;
use Closure;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

/**
 * "Sync now" button. Dispatches the sync job; with QUEUE_CONNECTION=sync it
 * runs inline and the result is shown straight away.
 *
 * @param  Closure|null  $spreadsheetIds  resolves the spreadsheets to sync (null = all)
 */
class SyncNowAction
{
    public static function make(string $name = 'syncNow', ?Closure $spreadsheetIds = null): Action
    {
        return Action::make($name)
            ->label('Sync now')
            ->icon(Heroicon::OutlinedArrowPath)
            ->color('primary')
            ->requiresConfirmation()
            ->modalDescription('Reads the configured tabs and writes their rows to the mapped tables.')
            ->action(function (Action $action) use ($spreadsheetIds) {
                $lastId = SyncRun::max('id') ?? 0;
                $ids = $spreadsheetIds ? $action->evaluate($spreadsheetIds) : null;

                RunSheetsSync::dispatch($ids);

                $run = SyncRun::where('id', '>', $lastId)->latest('id')->first();

                if (! $run || $run->status === SyncStatus::Running) {
                    Notification::make()->title('Sync queued')->body('The result will appear under Sync runs.')->info()->send();

                    return;
                }

                Notification::make()
                    ->title("Sync {$run->status->value}")
                    ->body("{$run->rows_written} rows written in {$run->durationForHumans()}.".($run->error ? " {$run->error}" : ''))
                    ->status(match ($run->status) {
                        SyncStatus::Success => 'success',
                        SyncStatus::Partial => 'warning',
                        default => 'danger',
                    })
                    ->actions([
                        Action::make('view')->label('View run')->url(SyncRunResource::getUrl('view', ['record' => $run])),
                    ])
                    ->send();
            });
    }
}
