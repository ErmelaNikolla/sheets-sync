<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SheetTab extends Model
{
    protected $fillable = ['spreadsheet_id', 'tab_name', 'header_row', 'is_active'];

    protected function casts(): array
    {
        return [
            'header_row' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function spreadsheet(): BelongsTo
    {
        return $this->belongsTo(Spreadsheet::class);
    }

    public function tableMappings(): HasMany
    {
        return $this->hasMany(TableMapping::class);
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    public function label(): string
    {
        return $this->spreadsheet->name.' › '.$this->tab_name;
    }
}
