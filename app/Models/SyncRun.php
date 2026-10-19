<?php

namespace App\Models;

use App\Enums\SyncStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SyncRun extends Model
{
    protected $fillable = ['trigger', 'status', 'rows_read', 'rows_written', 'error', 'started_at', 'finished_at'];

    protected function casts(): array
    {
        return [
            'status' => SyncStatus::class,
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(SyncRunItem::class);
    }

    public function durationForHumans(): ?string
    {
        if (! $this->started_at || ! $this->finished_at) {
            return null;
        }

        return round($this->started_at->diffInMilliseconds($this->finished_at) / 1000, 1).'s';
    }
}
