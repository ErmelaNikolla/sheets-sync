<?php

namespace App\Models;

use App\Enums\SyncMode;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TableMapping extends Model
{
    protected $fillable = ['sheet_tab_id', 'target_table', 'sync_mode', 'is_active'];

    protected function casts(): array
    {
        return [
            'sync_mode' => SyncMode::class,
            'is_active' => 'boolean',
        ];
    }

    public function sheetTab(): BelongsTo
    {
        return $this->belongsTo(SheetTab::class);
    }

    public function columnMappings(): HasMany
    {
        return $this->hasMany(ColumnMapping::class);
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }
}
