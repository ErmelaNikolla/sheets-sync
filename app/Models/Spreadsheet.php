<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class Spreadsheet extends Model
{
    protected $fillable = ['name', 'google_spreadsheet_id', 'is_active', 'last_synced_at'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'last_synced_at' => 'datetime',
        ];
    }

    public function tabs(): HasMany
    {
        return $this->hasMany(SheetTab::class);
    }

    public function tableMappings(): HasManyThrough
    {
        return $this->hasManyThrough(TableMapping::class, SheetTab::class);
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * Accepts either a bare spreadsheet ID or a full docs.google.com URL.
     */
    public static function extractId(string $value): string
    {
        $value = trim($value);

        if (preg_match('~/spreadsheets/d/([a-zA-Z0-9_-]+)~', $value, $m)) {
            return $m[1];
        }

        return $value;
    }

    public function url(): string
    {
        return 'https://docs.google.com/spreadsheets/d/'.$this->google_spreadsheet_id;
    }
}
