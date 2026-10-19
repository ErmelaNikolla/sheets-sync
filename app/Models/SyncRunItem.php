<?php

namespace App\Models;

use App\Enums\SyncStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SyncRunItem extends Model
{
    protected $fillable = [
        'sync_run_id', 'table_mapping_id', 'spreadsheet_name', 'tab_name', 'target_table',
        'status', 'rows_read', 'rows_written', 'warnings', 'error', 'started_at', 'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => SyncStatus::class,
            'warnings' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function syncRun(): BelongsTo
    {
        return $this->belongsTo(SyncRun::class);
    }

    public function tableMapping(): BelongsTo
    {
        return $this->belongsTo(TableMapping::class);
    }
}
